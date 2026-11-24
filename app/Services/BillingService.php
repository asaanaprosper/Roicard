<?php

namespace App\Services;

use App\Models\BillingEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionLapsedNotification;
use App\Support\PlanLimitKey;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function __construct(
        protected PaymentService $payments,
        protected EntitlementService $entitlements,
        protected MemberAccountService $accounts,
    ) {}

    public function providerConfigured(): bool
    {
        return $this->payments->configured();
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribe(User $user, Plan $plan, array $input = []): array
    {
        if (! $this->providerConfigured()) {
            throw ValidationException::withMessages([
                'billing' => 'Billing is not configured yet. No charge was initiated.',
            ]);
        }

        if (! $plan->is_active || ! $plan->hasConfiguredPrice() || ! $plan->isPaid()) {
            throw ValidationException::withMessages([
                'plan' => 'This plan does not have founder-approved pricing and billing details yet.',
            ]);
        }

        $current = $user->subscriptions()->current()->latest('id')->first();
        if ($current && (int) $current->plan_id === (int) $plan->id && $current->isActive()) {
            return [
                'subscription' => $current,
                'payment' => null,
                'redirect' => ['status' => 'already_active'],
                'message' => 'You are already subscribed to this plan.',
            ];
        }

        $idempotencyKey = 'subscription:' . $user->id . ':' . $plan->id . ':' . now()->format('YmdH');
        $payment = Payment::firstOrNew(['idempotency_key' => $idempotencyKey]);

        if ($payment->exists) {
            try {
                $verified = $payment->isPending() ? $this->payments->verify($payment->provider_reference) : null;
            } catch (\Throwable $e) {
                Log::warning('Subscription payment verification failed', ['error' => $e->getMessage()]);
                $verified = null;
            }

            if ($verified && $verified['status'] === 'success') {
                $this->markPaymentSuccess($payment);
            }

            if ($payment->status === Payment::STATUS_FAILED) {
                $payment->forceFill([
                    'amount' => $plan->price,
                    'currency' => $plan->currency ?: 'GHS',
                    'plan_id' => $plan->id,
                    'status' => Payment::STATUS_PENDING,
                    'provider_reference' => strtoupper(Str::random(16)),
                    'provider' => $this->payments->provider(),
                    'metadata' => [
                        'plan_key' => $plan->key,
                        'billing_interval' => $plan->billing_interval,
                    ],
                ])->save();
            }

            if ($payment->isPending()) {
                $redirect = $this->payments->initiate($payment);
            } else {
                $redirect = ['status' => $payment->status, 'reference' => $payment->provider_reference];
            }

            return [
                'payment' => $payment->fresh(),
                'redirect' => $redirect,
                'resumed' => true,
            ];
        }

        $payment->fill([
            'user_id' => $user->id,
            'amount' => $plan->price,
            'currency' => $plan->currency ?: 'GHS',
            'method' => $input['method'] ?? 'mobile_money',
            'momo_number' => $input['momo_number'] ?? null,
            'status' => Payment::STATUS_PENDING,
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'plan_id' => $plan->id,
            'provider_reference' => strtoupper(Str::random(16)),
            'provider' => $this->payments->provider(),
            'metadata' => [
                'plan_key' => $plan->key,
                'billing_interval' => $plan->billing_interval,
            ],
        ])->save();

        $this->recordEvent(
            eventKey: 'payment:initiated:' . $payment->id . ':' . $payment->provider_reference,
            eventType: 'payment.initiated',
            userId: $user->id,
            paymentId: $payment->id,
            provider: $payment->provider,
            payload: [
                'purpose' => Payment::PURPOSE_SUBSCRIPTION,
                'plan_id' => $plan->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ],
        );

        try {
            $redirect = $this->payments->initiate($payment);
        } catch (\Throwable $e) {
            $this->markPaymentFailed($payment, null, 'provider_initiation_failed');
            Log::error('Subscription payment initiation failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'billing' => 'The payment provider is unavailable. No subscription was activated.',
            ]);
        }

        return [
            'payment' => $payment->fresh(),
            'redirect' => $redirect,
        ];
    }

    public function markPaymentSuccess(Payment $payment, ?string $providerEventId = null): Payment
    {
        if ($payment->isCompleted()) {
            // The provider may retry a webhook after a downstream side effect
            // failed. The metadata guard makes this idempotent.
            $this->processSuccessfulPayment($payment);

            return $payment;
        }

        $payment->forceFill([
            'status' => Payment::STATUS_SUCCESS,
            'paid_at' => $payment->paid_at ?? now(),
            'provider_event_id' => $providerEventId ?? $payment->provider_event_id,
        ])->save();

        return $payment->fresh();
    }

    /**
     * A provider-reported failure must never downgrade a payment that is
     * already verified successful. It is recorded for history and safe retry.
     */
    public function markPaymentFailed(Payment $payment, ?string $providerEventId = null, ?string $reason = null): Payment
    {
        if ($payment->isCompleted()) {
            return $payment;
        }

        $metadata = $payment->metadata ?? [];
        $metadata['failed_at'] = $metadata['failed_at'] ?? now()->toIso8601String();

        if ($reason !== null && $reason !== '') {
            $metadata['failure_reason'] = $reason;
        }

        $payment->forceFill([
            'status' => Payment::STATUS_FAILED,
            'provider_event_id' => $providerEventId ?? $payment->provider_event_id,
            'metadata' => $metadata,
        ])->save();

        $this->recordEvent(
            eventKey: 'payment:failed:' . $payment->id . ':' . $payment->provider_reference,
            eventType: 'payment.failed',
            userId: $payment->user_id,
            paymentId: $payment->id,
            provider: $payment->provider,
            payload: [
                'purpose' => $payment->purpose,
                'error_code' => $reason,
            ],
        );

        return $payment->fresh();
    }

    /**
     * Put a subscription into the configured grace window. Access and the
     * verification mark stay in place until processExpiredSubscriptions()
     * expires it, or a verified renewal returns it to active.
     */
    public function markSubscriptionPastDue(
        Subscription $subscription,
        ?Payment $payment = null,
        string $eventType = 'subscription.payment_failed',
    ): Subscription {
        if (in_array($subscription->status, [Subscription::STATUS_EXPIRED, Subscription::STATUS_CANCELLED], true)) {
            return $subscription;
        }

        $metadata = $subscription->metadata ?? [];
        $firstFailure = ! isset($metadata['past_due_at']);
        $metadata['past_due_at'] = $metadata['past_due_at'] ?? now()->toIso8601String();

        $subscription->forceFill([
            'status' => Subscription::STATUS_PAST_DUE,
            'metadata' => $metadata,
        ])->save();

        if ($firstFailure) {
            $this->recordEvent(
                eventKey: 'subscription:past_due:' . $subscription->id . ':' . ($payment?->id ?? 'provider'),
                eventType: $eventType,
                userId: $subscription->user_id,
                subscriptionId: $subscription->id,
                paymentId: $payment?->id,
                provider: $payment?->provider,
                payload: ['grace_days' => max(0, (int) config('roicard.billing.grace_days', 0))],
            );
        }

        return $subscription->fresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function withoutPastDue(array $metadata): array
    {
        unset($metadata['past_due_at']);

        return $metadata;
    }


    /**
     * Called from the synchronous payment observer for every successful
     * payment. The purpose decides which one-time side effect runs.
     */
    public function processSuccessfulPayment(Payment $payment): void
    {
        if ($payment->metadata['processed_at'] ?? null) {
            return;
        }

        match ($payment->purpose) {
            Payment::PURPOSE_MEMBERSHIP => $this->processMembershipPayment($payment),
            Payment::PURPOSE_SUBSCRIPTION => $this->processSubscriptionPayment($payment),
            Payment::PURPOSE_CARD_ACTIVATION => app(CardActivationService::class)->activateFromPayment($payment),
            default => null,
        };

        $payment->forceFill([
            'metadata' => array_merge($payment->metadata ?? [], [
                'processed_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    protected function processMembershipPayment(Payment $payment): void
    {
        $this->accounts->activateMembership($payment->user);

        $this->recordEvent(
            eventKey: 'membership:payment:' . $payment->id,
            eventType: 'membership.payment_verified',
            userId: $payment->user_id,
            paymentId: $payment->id,
            provider: $payment->provider,
            payload: ['amount' => $payment->amount, 'currency' => $payment->currency],
        );

        app(ReferralService::class)->qualifyInvitee($payment->user, 'membership_active');
    }

    protected function processSubscriptionPayment(Payment $payment): void
    {
        $isRenewal = (bool) ($payment->metadata['renewal'] ?? false);

        if ($isRenewal && $payment->subscription_id) {
            $subscription = Subscription::query()->find($payment->subscription_id);

            if ($subscription) {
                $this->recordRenewal($subscription, $payment);
                app(ReferralService::class)->qualifyInvitee($payment->user, 'subscription_active');

                return;
            }
        }

        $this->activateSubscriptionFromPayment($payment);
        app(ReferralService::class)->qualifyInvitee($payment->user, 'subscription_active');
    }

    public function activateSubscriptionFromPayment(Payment $payment): Subscription
    {
        $plan = $payment->plan ?: Plan::find($payment->plan_id);

        if (! $plan) {
            throw new \RuntimeException('Subscription payment has no plan.');
        }

        return DB::transaction(function () use ($payment, $plan) {
            $subscription = $payment->subscription_id
                ? Subscription::find($payment->subscription_id)
                : $payment->user->subscriptions()->current()->latest('id')->first();

            if (! $subscription) {
                $subscription = new Subscription([
                    'user_id' => $payment->user_id,
                    'provider' => $payment->provider,
                ]);
            }

            $start = $subscription->current_period_end && $subscription->current_period_end->isFuture()
                ? $subscription->current_period_end->copy()
                : now();

            $metadata = $this->withoutPastDue($subscription->metadata ?? []);

            $subscription->fill([
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'provider' => $payment->provider,
                'provider_reference' => $subscription->provider_reference ?? $payment->provider_reference,
                'current_period_start' => $start,
                'current_period_end' => $this->periodEnd($plan, $start),
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'ended_at' => null,
                'metadata' => $metadata ?: null,
            ])->save();

            $payment->forceFill(['subscription_id' => $subscription->id])->save();
            $this->entitlements->assignPlan($payment->user, $plan);
            $this->bustUserProfileCaches($payment->user);

            $this->recordEvent(
                eventKey: 'subscription:activated:' . $payment->id,
                eventType: 'subscription.activated',
                userId: $payment->user_id,
                subscriptionId: $subscription->id,
                paymentId: $payment->id,
                provider: $payment->provider,
                payload: [
                    'plan_id' => $plan->id,
                    'plan_key' => $plan->key,
                    'period_end' => $subscription->current_period_end?->toIso8601String(),
                ],
            );

            return $subscription->fresh();
        });
    }

    public function recordRenewal(Subscription $subscription, Payment $payment): Subscription
    {
        $plan = $subscription->plan;

        if (! $plan) {
            throw new \RuntimeException('Subscription has no plan.');
        }

        if ((int) $payment->user_id !== (int) $subscription->user_id) {
            throw new \RuntimeException('Renewal payment does not belong to this subscription.');
        }

        $start = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end->copy()
            : now();

        $metadata = $this->withoutPastDue($subscription->metadata ?? []);
        $providerSubscriptionId = $payment->metadata['provider_subscription_id']
            ?? $subscription->provider_subscription_id;

        $subscription->forceFill([
            'status' => Subscription::STATUS_ACTIVE,
            'provider_subscription_id' => $providerSubscriptionId,
            'current_period_start' => $start,
            'current_period_end' => $this->periodEnd($plan, $start),
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'ended_at' => null,
            'metadata' => $metadata ?: null,
        ])->save();

        $this->entitlements->assignPlan($subscription->user, $plan);
        $this->bustUserProfileCaches($subscription->user);

        $this->recordEvent(
            eventKey: 'subscription:renewed:' . $payment->id,
            eventType: 'subscription.renewed',
            userId: $subscription->user_id,
            subscriptionId: $subscription->id,
            paymentId: $payment->id,
            provider: $payment->provider,
            payload: ['period_end' => $subscription->current_period_end?->toIso8601String()],
        );

        return $subscription->fresh();
    }

    public function cancelAtPeriodEnd(User $user): Subscription
    {
        $subscription = $user->subscriptions()->current()->latest('id')->first();

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => 'There is no active subscription to cancel.',
            ]);
        }

        if ($subscription->cancel_at_period_end) {
            return $subscription;
        }

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
        ])->save();

        $this->recordEvent(
            eventKey: 'subscription:cancel_requested:' . $subscription->id . ':' . now()->timestamp,
            eventType: 'subscription.cancel_requested',
            userId: $subscription->user_id,
            subscriptionId: $subscription->id,
            payload: ['period_end' => $subscription->current_period_end?->toIso8601String()],
        );

        return $subscription->fresh();
    }

    public function resume(User $user): Subscription
    {
        $subscription = $user->subscriptions()->current()->latest('id')->first();

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => 'There is no subscription to resume.',
            ]);
        }

        if (! $subscription->cancel_at_period_end) {
            return $subscription;
        }

        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
        ])->save();

        $this->recordEvent(
            eventKey: 'subscription:resume_requested:' . $subscription->id . ':' . now()->timestamp,
            eventType: 'subscription.resume_requested',
            userId: $subscription->user_id,
            subscriptionId: $subscription->id,
            payload: ['period_end' => $subscription->current_period_end?->toIso8601String()],
        );

        return $subscription->fresh();
    }

    /**
     * Daily billing maintenance: expire cancelled/ended subscriptions and
     * return the account to the configured Free plan without deleting data.
     */
    public function processExpiredSubscriptions(): array
    {
        $graceDays = max(0, (int) config('roicard.billing.grace_days', 0));
        $processed = ['past_due' => 0, 'expired' => 0, 'cancelled' => 0];

        Subscription::query()
            ->current()
            ->whereNotNull('current_period_end')
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($graceDays, &$processed) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->current_period_end->isFuture()) {
                        continue;
                    }

                    // An explicit cancellation ends at the period boundary,
                    // not after a grace window.
                    if ($subscription->cancel_at_period_end) {
                        $this->cancelNow($subscription);
                        $processed['cancelled']++;
                        continue;
                    }

                    if ($graceDays === 0) {
                        $this->expire($subscription, 'subscription.period_ended');
                        $processed['expired']++;
                        continue;
                    }

                    $pastDueAt = $subscription->metadata['past_due_at'] ?? null;
                    if (! $pastDueAt) {
                        $this->markSubscriptionPastDue($subscription, null, 'subscription.past_due');
                        $processed['past_due']++;
                        continue;
                    }

                    // Grace starts no earlier than the paid period end, even
                    // when the provider reported a failure before that date.
                    $failureAt = \Carbon\Carbon::parse($pastDueAt);
                    $graceStart = $failureAt->greaterThan($subscription->current_period_end)
                        ? $failureAt
                        : $subscription->current_period_end->copy();

                    if ($graceStart->copy()->addDays($graceDays)->isPast()) {
                        $this->expire($subscription, 'subscription.grace_expired');
                        $processed['expired']++;
                    }
                }
            });

        return $processed;
    }

    public function expire(Subscription $subscription, string $eventType = 'subscription.expired'): Subscription
    {
        return $this->endSubscription($subscription, Subscription::STATUS_EXPIRED, $eventType);
    }

    public function cancelNow(Subscription $subscription): Subscription
    {
        return $this->endSubscription($subscription, Subscription::STATUS_CANCELLED, 'subscription.cancelled');
    }

    protected function endSubscription(Subscription $subscription, string $status, string $eventType): Subscription
    {
        $ended = DB::transaction(function () use ($subscription, $status, $eventType) {
            $subscription->forceFill([
                'status' => $status,
                'ended_at' => now(),
                'cancel_at_period_end' => false,
            ])->save();

            $free = $this->freePlan();
            $this->entitlements->assignPlan($subscription->user, $free);
            $this->bustUserProfileCaches($subscription->user);

            $refundWindow = config('roicard.referrals.refund_window_days');
            if ($refundWindow !== null && $refundWindow !== ''
                && $subscription->current_period_start
                && $subscription->ended_at
                && $subscription->current_period_start->diffInDays($subscription->ended_at) <= (int) $refundWindow) {
                app(ReferralService::class)->voidForInvitee(
                    $subscription->user,
                    'Subscription ended inside the configured refund window.'
                );
            }

            $this->recordEvent(
                eventKey: $eventType . ':' . $subscription->id . ':' . now()->timestamp,
                eventType: $eventType,
                userId: $subscription->user_id,
                subscriptionId: $subscription->id,
                payload: ['free_plan_id' => $free?->id, 'status' => $status],
            );

            return $subscription->fresh();
        });

        if ($status === Subscription::STATUS_EXPIRED && $ended->user) {
            $ended->user->notify(new SubscriptionLapsedNotification($ended));
        }

        return $ended;
    }

    public function freePlan(): ?Plan
    {
        return Plan::query()->active()->where('is_default', true)->first()
            ?? Plan::query()->active()
                ->where('key', (string) config('roicard.plans.default_key', 'free'))
                ->first();
    }

    /**
     * @return array{duplicate: bool, event: BillingEvent}
     */
    public function handleWebhook(string $provider, array $payload, string $signature, ?string $rawBody = null): array
    {
        if ($provider !== $this->payments->provider()) {
            throw ValidationException::withMessages([
                'provider' => 'Webhook provider does not match the configured payment provider.',
            ]);
        }

        if (! $this->payments->verifyWebhook($provider, $payload, $signature, $rawBody)) {
            throw ValidationException::withMessages(['signature' => 'Invalid webhook signature.']);
        }

        $providerEventId = (string) (
            $payload['data']['id']
            ?? $payload['id']
            ?? $payload['event_id']
            ?? hash('sha256', json_encode($payload))
        );

        $eventKey = $provider . ':' . $providerEventId;
        $existing = BillingEvent::where('event_key', $eventKey)->first();

        if ($existing && $existing->processed_at !== null) {
            return ['duplicate' => true, 'event' => $existing];
        }

        $eventType = (string) ($payload['event'] ?? $payload['type'] ?? 'unknown');
        $reference = (string) (
            $payload['data']['reference']
            ?? $payload['data']['checkoutId']
            ?? $payload['data']['clientReference']
            ?? $payload['data']['client_reference']
            ?? $payload['reference']
            ?? ''
        );

        $event = $existing ?: BillingEvent::create([
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);

        try {
            $handled = $this->processWebhookPayload($event, $eventType, $payload, $reference);
            $event->forceFill([
                'processed_at' => now(),
                'user_id' => $handled['user_id'] ?? null,
                'payment_id' => $handled['payment_id'] ?? null,
                'subscription_id' => $handled['subscription_id'] ?? null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('Billing webhook processing failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['duplicate' => false, 'event' => $event->fresh(), 'handled' => $handled];
    }

    /**
     * @return array<string, mixed>
     */
    protected function processWebhookPayload(BillingEvent $event, string $eventType, array $payload, string $reference): array
    {
        $failureEvents = [
            'charge.failed',
            'invoice.payment_failed',
            'invoice.failed',
        ];

        if (in_array($eventType, $failureEvents, true)) {
            return $this->processFailedPaymentWebhook($event, $payload, $reference, $eventType);
        }

        $successEvents = [
            'charge.success',
            'invoice.payment_success',
            'invoice.paid',
            'subscription.create',
        ];

        if (in_array($eventType, $successEvents, true) && $reference !== '') {
            $payment = Payment::where('provider_reference', $reference)->first();

            if ($payment) {
                $this->assertProviderAmountMatches($event, $payment, $payload);
                $this->markPaymentSuccess($payment, (string) ($payload['data']['id'] ?? $event->provider_event_id));

                return [
                    'user_id' => $payment->user_id,
                    'payment_id' => $payment->id,
                    'subscription_id' => $payment->subscription_id,
                ];
            }

            // Provider-initiated renewal: some providers send an invoice event
            // whose reference is not a checkout reference. If it maps to a
            // known subscription, record the renewal as a verified payment.
            $providerSubscriptionId = (string) (
                $payload['data']['subscription_code']
                ?? $payload['data']['subscription']
                ?? $payload['data']['subscription_id']
                ?? ''
            );
            $subscription = $providerSubscriptionId !== ''
                ? Subscription::where('provider_subscription_id', $providerSubscriptionId)->first()
                : null;

            if ($subscription && $subscription->plan) {
                $minorAmount = $payload['data']['amount'] ?? null;
                $amount = $minorAmount !== null
                    ? ((float) $minorAmount / 100)
                    : (float) $subscription->plan->price;

                $renewal = Payment::create([
                    'user_id' => $subscription->user_id,
                    'amount' => $amount,
                    'currency' => $subscription->plan->currency ?: 'GHS',
                    'status' => Payment::STATUS_PENDING,
                    'purpose' => Payment::PURPOSE_SUBSCRIPTION,
                    'plan_id' => $subscription->plan_id,
                    'subscription_id' => $subscription->id,
                    'provider' => $event->provider,
                    'provider_reference' => $reference !== '' ? $reference : 'renewal-' . $event->id,
                    'provider_event_id' => $event->provider_event_id,
                    'metadata' => [
                        'renewal' => true,
                        'provider_subscription_id' => $providerSubscriptionId,
                    ],
                ]);

                $this->markPaymentSuccess($renewal, $event->provider_event_id);

                return [
                    'user_id' => $renewal->user_id,
                    'payment_id' => $renewal->id,
                    'subscription_id' => $subscription->id,
                ];
            }
        }

        if (str_contains($eventType, 'subscription.expired') || str_contains($eventType, 'subscription.ended')) {
            $providerSubscriptionId = (string) (
                $payload['data']['subscription_code']
                ?? $payload['data']['subscription']
                ?? $payload['data']['id']
                ?? ''
            );
            $subscription = $providerSubscriptionId !== ''
                ? Subscription::where('provider_subscription_id', $providerSubscriptionId)->first()
                : null;

            if ($subscription) {
                if ($subscription->current_period_end && $subscription->current_period_end->isFuture()) {
                    $subscription->forceFill([
                        'cancel_at_period_end' => true,
                        'cancelled_at' => $subscription->cancelled_at ?? now(),
                    ])->save();

                    $this->recordEvent(
                        eventKey: 'subscription:cancel_requested:' . $subscription->id . ':' . $event->id,
                        eventType: 'subscription.cancel_requested',
                        userId: $subscription->user_id,
                        subscriptionId: $subscription->id,
                        provider: $event->provider,
                        payload: ['source' => 'provider', 'provider_event_type' => $eventType],
                    );
                } else {
                    $this->expire($subscription, 'subscription.expired');
                }

                return [
                    'user_id' => $subscription->user_id,
                    'subscription_id' => $subscription->id,
                ];
            }
        }

        if (str_contains($eventType, 'subscription.disable')
            || str_contains($eventType, 'subscription.cancelled')
            || str_contains($eventType, 'subscription.not_renew')) {
            $providerSubscriptionId = (string) ($payload['data']['subscription_code'] ?? $payload['data']['id'] ?? '');
            $subscription = Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();

            if ($subscription) {
                $subscription->forceFill([
                    'cancel_at_period_end' => true,
                    'cancelled_at' => $subscription->cancelled_at ?? now(),
                ])->save();

                $this->recordEvent(
                    eventKey: 'subscription:cancel_requested:' . $subscription->id . ':' . $event->id,
                    eventType: 'subscription.cancel_requested',
                    userId: $subscription->user_id,
                    subscriptionId: $subscription->id,
                    provider: $event->provider,
                    payload: ['source' => 'provider', 'provider_event_type' => $eventType],
                );

                return [
                    'user_id' => $subscription->user_id,
                    'subscription_id' => $subscription->id,
                ];
            }
        }

        return [];
    }

    /**
     * Handle a verified provider failure/late-payment event without revoking
     * access immediately. The grace window decides when the plan lapses.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /**
     * Paystack sends amounts in the currency's minor unit. Never activate a
     * payment whose signed webhook amount or currency differs from the
     * server-created payment.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function assertProviderAmountMatches(BillingEvent $event, Payment $payment, array $payload): void
    {
        if ($event->provider !== 'paystack') {
            return;
        }

        $receivedAmount = $payload['data']['amount'] ?? null;

        if ($receivedAmount !== null) {
            $expectedMinor = (int) round((float) $payment->amount * 100);

            if ((int) $receivedAmount !== $expectedMinor) {
                Log::critical('Payment webhook amount mismatch', [
                    'payment_id' => $payment->id,
                    'expected_minor' => $expectedMinor,
                    'received_minor' => (int) $receivedAmount,
                ]);

                throw ValidationException::withMessages(['amount' => 'Payment amount mismatch.']);
            }
        }

        $receivedCurrency = $payload['data']['currency'] ?? null;

        if ($receivedCurrency !== null
            && mb_strtoupper((string) $receivedCurrency) !== mb_strtoupper((string) $payment->currency)) {
            Log::critical('Payment webhook currency mismatch', [
                'payment_id' => $payment->id,
                'expected_currency' => $payment->currency,
                'received_currency' => $receivedCurrency,
            ]);

            throw ValidationException::withMessages(['currency' => 'Payment currency mismatch.']);
        }
    }

    protected function processFailedPaymentWebhook(
        BillingEvent $event,
        array $payload,
        string $reference,
        string $eventType,
    ): array {
        $providerSubscriptionId = (string) (
            $payload['data']['subscription_code']
            ?? $payload['data']['subscription']
            ?? $payload['data']['subscription_id']
            ?? ''
        );

        $subscription = $providerSubscriptionId !== ''
            ? Subscription::where('provider_subscription_id', $providerSubscriptionId)->first()
            : null;

        $payment = $reference !== ''
            ? Payment::where('provider_reference', $reference)->first()
            : null;

        if (! $subscription && $payment?->subscription_id) {
            $subscription = Subscription::find($payment->subscription_id);
        }

        if (! $payment && $subscription && $subscription->plan) {
            $payment = Payment::firstOrCreate(
                ['provider_reference' => $reference !== '' ? $reference : 'failed-renewal-' . $event->id],
                [
                    'user_id' => $subscription->user_id,
                    'amount' => $subscription->plan->price,
                    'currency' => $subscription->plan->currency ?: 'GHS',
                    'status' => Payment::STATUS_FAILED,
                    'purpose' => Payment::PURPOSE_SUBSCRIPTION,
                    'plan_id' => $subscription->plan_id,
                    'subscription_id' => $subscription->id,
                    'provider' => $event->provider,
                    'provider_event_id' => $event->provider_event_id,
                    'metadata' => [
                        'renewal' => true,
                        'provider_subscription_id' => $providerSubscriptionId,
                        'failure_event' => $eventType,
                    ],
                ],
            );
        }

        if ($payment && ! $payment->isCompleted()) {
            $payment = $this->markPaymentFailed($payment, $event->provider_event_id, $eventType);
        }

        // A late failure for an already-successful payment must not downgrade
        // the subscription. Only put it into grace when the payment is not
        // verified successful.
        if ($subscription && (! $payment || ! $payment->isCompleted())) {
            $subscription = $this->markSubscriptionPastDue($subscription, $payment, 'subscription.payment_failed');
        }

        return [
            'user_id' => $payment?->user_id ?? $subscription?->user_id,
            'payment_id' => $payment?->id,
            'subscription_id' => $subscription?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function history(User $user): array
    {
        return [
            'payments' => $user->payments()->latest()->limit(100)->get(),
            'subscription' => $user->subscriptions()->with('plan')->latest('id')->first(),
            'events' => BillingEvent::where('user_id', $user->id)
                ->latest()
                ->limit(100)
                ->get(),
        ];
    }

    protected function periodEnd(Plan $plan, CarbonInterface $start): CarbonInterface
    {
        return $plan->billing_interval === 'year'
            ? $start->copy()->addYear()
            : $start->copy()->addMonth();
    }

    protected function bustUserProfileCaches(User $user): void
    {
        $user->profiles()->get()->each(fn ($profile) => $profile->bustPublicCache());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function recordEvent(
        string $eventKey,
        string $eventType,
        ?int $userId = null,
        ?int $subscriptionId = null,
        ?int $paymentId = null,
        ?string $provider = null,
        array $payload = [],
    ): BillingEvent {
        return BillingEvent::firstOrCreate(
            ['event_key' => $eventKey],
            [
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'payment_id' => $paymentId,
                'provider' => $provider,
                'event_type' => $eventType,
                'payload' => $payload ?: null,
                'processed_at' => now(),
            ],
        );
    }
}
