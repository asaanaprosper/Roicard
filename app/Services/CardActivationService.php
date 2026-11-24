<?php

namespace App\Services;

use App\Models\BillingEvent;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\SmartCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CardActivationService
{
    public function __construct(protected PaymentService $payments) {}

    /**
     * @return array<string, mixed>
     */
    public function initiate(User $user, Profile $profile, string $cardId, array $input = []): array
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            abort(403, 'Profile does not belong to this account.');
        }

        if (! $this->payments->configured()) {
            throw ValidationException::withMessages([
                'billing' => 'Card activation payments are not configured yet. No charge was initiated.',
            ]);
        }

        $card = SmartCard::where('card_id', mb_strtoupper(trim($cardId)))->first();

        if (! $card) {
            throw ValidationException::withMessages([
                'card_id' => 'This card ID is not registered by ROICARD.',
            ]);
        }

        if ($card->inventory_status === SmartCard::STATUS_DEACTIVATED || $card->status === 'deactivated') {
            throw ValidationException::withMessages([
                'card_id' => 'This card has been deactivated. Contact support for a replacement.',
            ]);
        }

        if ($card->binding_key !== null && $card->binding_key !== 'profile:' . $profile->id) {
            throw ValidationException::withMessages([
                'card_id' => 'This card is already bound to another member profile.',
            ]);
        }

        if ($card->user_id !== null && (int) $card->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'card_id' => 'This card is assigned to a different account.',
            ]);
        }

        $currentBinding = SmartCard::where('binding_key', 'profile:' . $profile->id)->first();
        if ($currentBinding && (int) $currentBinding->id !== (int) $card->id) {
            throw ValidationException::withMessages([
                'profile_id' => 'This profile already has an active card. Deactivate it before activating another.',
            ]);
        }

        if ($card->binding_key === 'profile:' . $profile->id) {
            return [
                'card' => $card->fresh()->load('profile:id,slug,display_name'),
                'payment' => null,
                'redirect' => ['status' => 'already_active'],
            ];
        }

        $key = 'card_activation:' . $user->id . ':' . $card->id;
        $payment = Payment::firstOrNew(['idempotency_key' => $key]);

        if ($payment->exists) {
            if ($payment->status === Payment::STATUS_FAILED) {
                $payment->forceFill([
                    'status' => Payment::STATUS_PENDING,
                    'provider_reference' => strtoupper(Str::random(16)),
                    'provider' => $this->payments->provider(),
                ])->save();
            }

            if ($payment->isPending()) {
                $redirect = $this->payments->initiate($payment);
            } else {
                $redirect = ['status' => $payment->status, 'reference' => $payment->provider_reference];
            }

            return ['card' => $card->fresh(), 'payment' => $payment->fresh(), 'redirect' => $redirect];
        }

        $payment->fill([
            'user_id' => $user->id,
            'amount' => (float) config('roicard.card_activation_fee', 75),
            'currency' => 'GHS',
            'method' => $input['method'] ?? 'mobile_money',
            'momo_number' => $input['momo_number'] ?? null,
            'status' => Payment::STATUS_PENDING,
            'purpose' => Payment::PURPOSE_CARD_ACTIVATION,
            'smart_card_id' => $card->id,
            'profile_id' => $profile->id,
            'provider_reference' => strtoupper(Str::random(16)),
            'provider' => $this->payments->provider(),
            'metadata' => [
                'card_id' => $card->card_id,
                'profile_slug' => $profile->slug,
                'activation_fee' => (float) config('roicard.card_activation_fee', 75),
            ],
        ])->save();

        try {
            $redirect = $this->payments->initiate($payment);
        } catch (\Throwable $e) {
            $payment->update(['status' => Payment::STATUS_FAILED]);
            throw ValidationException::withMessages([
                'billing' => 'The payment provider is unavailable. No card was activated.',
            ]);
        }

        return ['card' => $card->fresh(), 'payment' => $payment->fresh(), 'redirect' => $redirect];
    }

    public function activateFromPayment(Payment $payment): SmartCard
    {
        if ($payment->purpose !== Payment::PURPOSE_CARD_ACTIVATION
            || ! $payment->smart_card_id
            || ! $payment->profile_id) {
            throw new \RuntimeException('Invalid card activation payment.');
        }

        $card = DB::transaction(function () use ($payment) {
            $card = SmartCard::whereKey($payment->smart_card_id)->lockForUpdate()->firstOrFail();
            $profile = Profile::whereKey($payment->profile_id)->lockForUpdate()->firstOrFail();

            if ((int) $profile->user_id !== (int) $payment->user_id) {
                throw new \RuntimeException('Card activation profile does not belong to the payer.');
            }

            $key = 'profile:' . $profile->id;
            if ($card->binding_key === $key) {
                return $card;
            }

            if ($card->binding_key !== null) {
                throw new \RuntimeException('Card is already bound to another profile.');
            }

            $other = SmartCard::where('binding_key', $key)->whereKeyNot($card->id)->first();
            if ($other) {
                throw new \RuntimeException('Profile already has another active card.');
            }

            $card->forceFill([
                'user_id' => $payment->user_id,
                'profile_id' => $profile->id,
                'activation_payment_id' => $payment->id,
                'inventory_status' => SmartCard::STATUS_ACTIVE,
                'binding_key' => $key,
                'activated_at' => now(),
                'deactivated_at' => null,
            ])->save();

            BillingEvent::firstOrCreate(
                ['event_key' => 'card:activated:' . $card->id . ':' . $payment->id],
                [
                    'user_id' => $payment->user_id,
                    'payment_id' => $payment->id,
                    'event_type' => 'card.activated',
                    'payload' => ['card_id' => $card->card_id, 'profile_id' => $profile->id],
                    'processed_at' => now(),
                ],
            );

            return $card->fresh();
        });

        app(ReferralService::class)->qualifyInvitee($payment->user, 'card_activated');

        return $card;
    }

    public function deactivate(User $user, SmartCard $card, bool $asAdmin = false): SmartCard
    {
        if (! $asAdmin && (int) $card->user_id !== (int) $user->id) {
            abort(403, 'This card does not belong to your account.');
        }

        $card->forceFill([
            'inventory_status' => SmartCard::STATUS_DEACTIVATED,
            'binding_key' => null,
            'deactivated_at' => now(),
        ])->save();

        BillingEvent::firstOrCreate(
            ['event_key' => 'card:deactivated:' . $card->id . ':' . now()->timestamp],
            [
                'user_id' => $card->user_id,
                'event_type' => 'card.deactivated',
                'payload' => ['card_id' => $card->card_id, 'by_admin' => $asAdmin],
                'processed_at' => now(),
            ],
        );

        return $card->fresh();
    }

    public function replace(User $admin, SmartCard $oldCard, string $newCardId, ?int $profileId = null): SmartCard
    {
        $profileId ??= $oldCard->profile_id;

        if (! $profileId) {
            throw ValidationException::withMessages([
                'card' => 'The existing card has no bound profile to replace.',
            ]);
        }

        return DB::transaction(function () use ($admin, $oldCard, $newCardId, $profileId) {
            $newCard = SmartCard::where('card_id', mb_strtoupper(trim($newCardId)))->lockForUpdate()->first();

            if (! $newCard) {
                throw ValidationException::withMessages(['new_card_id' => 'Replacement card ID is not registered.']);
            }

            if ($newCard->binding_key !== null || $newCard->inventory_status === SmartCard::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['new_card_id' => 'Replacement card is already bound.']);
            }

            $key = 'profile:' . $profileId;
            $oldCard->forceFill([
                'inventory_status' => SmartCard::STATUS_DEACTIVATED,
                'binding_key' => null,
                'deactivated_at' => now(),
            ])->save();

            $newCard->forceFill([
                'user_id' => $oldCard->user_id,
                'profile_id' => $profileId,
                'inventory_status' => SmartCard::STATUS_ACTIVE,
                'binding_key' => $key,
                'activated_at' => now(),
                'deactivated_at' => null,
            ])->save();

            BillingEvent::firstOrCreate(
                ['event_key' => 'card:replaced:' . $oldCard->id . ':' . $newCard->id],
                [
                    'user_id' => $oldCard->user_id,
                    'event_type' => 'card.replaced',
                    'payload' => [
                        'old_card_id' => $oldCard->card_id,
                        'new_card_id' => $newCard->card_id,
                        'profile_id' => $profileId,
                        'admin_id' => $admin->id,
                    ],
                    'processed_at' => now(),
                ],
            );

            return $newCard->fresh();
        });
    }

    /**
     * @return array<int, SmartCard>
     */
    public function forUser(User $user): array
    {
        return SmartCard::where('user_id', $user->id)
            ->with('profile:id,slug,display_name,title')
            ->orderByDesc('activated_at')
            ->get()
            ->all();
    }
}
