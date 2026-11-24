<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralRewardEntry;
use App\Models\User;
use App\Notifications\ReferralQualifiedNotification;
use App\Notifications\ReferralRewardCreditedNotification;
use App\Notifications\ReferralRewardPaidNotification;
use App\Notifications\ReferralSignupNotification;
use App\Services\Notifications\AdminAlertService;
use App\Support\NotificationType;
use App\Support\PlanLimitKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    /**
     * Triggers emitted by the application. The founder configures one of
     * these values in REFERRAL_QUALIFICATION_TRIGGER; no default is assumed.
     *
     * @var list<string>
     */
    public const SUPPORTED_TRIGGERS = [
        'membership_active',
        'subscription_active',
        'card_activated',
    ];

    public function __construct(
        protected EntitlementService $entitlements,
        protected QRService $qr,
    ) {}

    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        do {
            $code = 'RC' . strtoupper(Str::random(8));
        } while (User::where('referral_code', $code)->exists());

        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    public function linkFor(User $user): string
    {
        return rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/auth/register?ref=' . urlencode($this->codeFor($user));
    }

    public function qrSvgFor(User $user): string
    {
        return $this->qr->generate($this->linkFor($user));
    }

    public function attribute(User $invitee, ?string $code, ?string $ip = null, ?string $source = 'signup'): ?Referral
    {
        if (! config('roicard.referrals.enabled', false) || ! $code) {
            return null;
        }

        $referrer = User::where('referral_code', mb_strtoupper(trim($code)))->first();

        if (! $referrer) {
            return null;
        }

        if ((int) $referrer->id === (int) $invitee->id
            || mb_strtolower($referrer->email) === mb_strtolower($invitee->email)) {
            return null;
        }

        $risk = [];
        if (config('roicard.referrals.same_ip_block', false)
            && $ip !== null
            && $referrer->registration_ip !== null
            && $ip === $referrer->registration_ip) {
            $risk[] = 'same_ip_as_referrer';
        }

        $days = max(1, (int) config('roicard.referrals.attribution_days', 30));
        $expires = now()->addDays($days);

        $referral = Referral::firstOrCreate(
            ['invitee_user_id' => $invitee->id],
            [
                'referrer_user_id' => $referrer->id,
                'referral_code' => $referrer->referral_code,
                'status' => $risk === [] ? Referral::STATUS_ATTRIBUTED : Referral::STATUS_VOIDED,
                'source' => $source,
                'invitee_email' => $invitee->email,
                'attribution_ip' => $ip,
                'risk_flags' => $risk ?: null,
                'attributed_at' => now(),
                'expires_at' => $expires,
                'voided_at' => $risk === [] ? null : now(),
            ],
        );

        if ($referral->wasRecentlyCreated) {
            $invitee->forceFill([
                'referred_by_user_id' => $referrer->id,
                'referral_attributed_at' => now(),
                'referral_attribution_expires_at' => $expires,
            ])->save();

            app(AnalyticsService::class)->record($referrer->id, 'referral_signup', [
                'referral_id' => $referral->id,
            ]);

            $referrer->notify(new ReferralSignupNotification($referral->fresh()));
        }

        return $referral->fresh();
    }

    public function qualifyInvitee(User $invitee, string $trigger): ?Referral
    {
        if (! config('roicard.referrals.enabled', false)) {
            return null;
        }

        $configuredTrigger = config('roicard.referrals.qualification_trigger');
        if (! $configuredTrigger || $configuredTrigger !== $trigger) {
            return null;
        }

        $referral = Referral::where('invitee_user_id', $invitee->id)
            ->where('status', Referral::STATUS_ATTRIBUTED)
            ->first();

        if (! $referral) {
            return null;
        }

        if (! $referral->isWithinAttributionWindow()) {
            $referral->forceFill(['status' => Referral::STATUS_EXPIRED])->save();
            return $referral;
        }

        if ((int) $referral->referrer_user_id === (int) $invitee->id) {
            $this->flag($referral, 'self_referral');
            return $referral;
        }

        // An invitee with a successful payment before attribution cannot be
        // rewarded for a new payment.
        $priorPayment = Payment::where('user_id', $invitee->id)
            ->where('status', Payment::STATUS_SUCCESS)
            ->where('created_at', '<', $referral->attributed_at)
            ->exists();

        if ($priorPayment) {
            $this->flag($referral, 'payment_before_attribution');
            return $referral;
        }

        if ($referral->rewardEntries()->whereIn('status', [
            ReferralRewardEntry::STATUS_AVAILABLE,
            ReferralRewardEntry::STATUS_PAID,
        ])->exists()) {
            return $referral;
        }

        $riskFlags = $this->qualificationRiskFlags($referral, $invitee);
        if ($riskFlags !== []) {
            $this->flag($referral, $riskFlags);

            return $referral->fresh();
        }

        $referrer = $referral->referrer;
        $limit = $this->entitlements->limit($referrer, PlanLimitKey::REFERRAL_REWARDS);
        $used = $this->rewardUsageCount($referrer);

        if ($limit !== null && $used >= $limit) {
            $referral->forceFill([
                'status' => Referral::STATUS_CAPPED,
                'qualified_at' => now(),
            ])->save();

            $this->alertReferralCap($referral, 'plan_reward_limit', [
                'limit' => $limit,
                'used' => $used,
            ]);

            return $referral->fresh();
        }

        $monthlyCap = config('roicard.referrals.monthly_reward_cap');
        if ($monthlyCap !== null && $monthlyCap !== '') {
            $monthlyCount = $this->rewardUsageCount($referrer, thisMonthOnly: true);

            if ($monthlyCount >= (int) $monthlyCap) {
                $referral->forceFill([
                    'status' => Referral::STATUS_CAPPED,
                    'qualified_at' => now(),
                ])->save();

                $this->alertReferralCap($referral, 'monthly_reward_cap', [
                    'monthly_cap' => (int) $monthlyCap,
                    'used_this_month' => $monthlyCount,
                ]);

                return $referral->fresh();
            }
        }

        $amount = config('roicard.referrals.reward_amount');
        $currency = config('roicard.referrals.currency', 'GHS');
        $rewardAmount = ($amount !== null && $amount !== '') ? (float) $amount : 0.0;

        $totalBudget = config('roicard.referrals.total_budget');
        if ($rewardAmount > 0 && $totalBudget !== null && $totalBudget !== '') {
            $committed = $this->committedRewardValue();

            if ($committed + $rewardAmount > (float) $totalBudget) {
                $referral->forceFill([
                    'status' => Referral::STATUS_CAPPED,
                    'qualified_at' => now(),
                    'metadata' => array_merge($referral->metadata ?? [], [
                        'budget_capped_at' => now()->toIso8601String(),
                        'committed_before_cap' => $committed,
                        'configured_total_budget' => (float) $totalBudget,
                    ]),
                ])->save();

                $this->alertReferralCap($referral, 'total_budget_cap', [
                    'committed_before_cap' => $committed,
                    'reward_amount' => $rewardAmount,
                    'total_budget' => (float) $totalBudget,
                ]);

                return $referral->fresh();
            }
        }

        DB::transaction(function () use ($referral, $rewardAmount, $currency) {
            $referral->forceFill([
                'status' => Referral::STATUS_QUALIFIED,
                'qualified_at' => now(),
            ])->save();

            if ($rewardAmount > 0) {
                ReferralRewardEntry::firstOrCreate(
                    ['referral_id' => $referral->id, 'status' => ReferralRewardEntry::STATUS_PENDING],
                    [
                        'referrer_user_id' => $referral->referrer_user_id,
                        'invitee_user_id' => $referral->invitee_user_id,
                        'entry_type' => 'credit',
                        'amount' => $rewardAmount,
                        'delta_amount' => 0,
                        'currency' => $currency,
                        'note' => 'Pending founder-configurable referral reward.',
                    ],
                );
            }
        });

        $this->maybeAlertBudget();

        app(AnalyticsService::class)->record($referral->referrer_user_id, 'referral_qualified', [
            'referral_id' => $referral->id,
            'reward_amount' => $amount,
        ]);

        $referrer = $referral->referrer;

        if ($referrer) {
            $reward = $referral->rewardEntries()->orderByDesc('id')->first();
            $referrer->notify(new ReferralQualifiedNotification($referral->fresh(), $reward));
        }

        return $referral->fresh();
    }

    public function markAvailable(Referral $referral, User $actor, ?string $note = null): ReferralRewardEntry
    {
        if (! in_array($referral->status, [Referral::STATUS_QUALIFIED, Referral::STATUS_AVAILABLE], true)) {
            throw ValidationException::withMessages(['referral' => 'Only qualified referrals can become available.']);
        }

        $this->assertRefundWindowElapsed($referral);

        $pending = $referral->rewardEntries()
            ->whereIn('status', [
                ReferralRewardEntry::STATUS_PENDING,
                ReferralRewardEntry::STATUS_AVAILABLE,
            ])
            ->orderBy('id')
            ->first();

        if (! $pending) {
            throw ValidationException::withMessages([
                'referral' => 'No reward amount has been configured for this referral.',
            ]);
        }

        $existing = $referral->rewardEntries()->where('status', ReferralRewardEntry::STATUS_AVAILABLE)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($referral, $pending, $actor, $note) {
            $entry = ReferralRewardEntry::create([
                'referral_id' => $referral->id,
                'referrer_user_id' => $referral->referrer_user_id,
                'invitee_user_id' => $referral->invitee_user_id,
                'entry_type' => 'credit',
                'status' => ReferralRewardEntry::STATUS_AVAILABLE,
                'amount' => $pending->amount,
                'delta_amount' => $pending->amount,
                'currency' => $pending->currency,
                'note' => $note ?: 'Referral reward approved.',
                'created_by' => $actor->id,
            ]);

            $referral->forceFill(['status' => Referral::STATUS_AVAILABLE])->save();

            app(AnalyticsService::class)->record($referral->referrer_user_id, 'referral_reward_available', [
                'referral_id' => $referral->id,
                'amount' => $entry->amount,
            ]);

            $referral->referrer?->notify(new ReferralRewardCreditedNotification($entry));

            return $entry;
        });
    }

    public function void(Referral $referral, ?User $actor, string $reason = 'Voided by administrator.'): ReferralRewardEntry
    {
        if ($referral->rewardEntries()->where('status', ReferralRewardEntry::STATUS_PAID)->exists()) {
            throw ValidationException::withMessages([
                'referral' => 'A paid reward cannot be voided through this ledger.',
            ]);
        }

        if ($referral->rewardEntries()->where('status', ReferralRewardEntry::STATUS_VOIDED)->exists()) {
            return $referral->rewardEntries()->where('status', ReferralRewardEntry::STATUS_VOIDED)->first();
        }

        $available = $referral->rewardEntries()
            ->where('status', ReferralRewardEntry::STATUS_AVAILABLE)
            ->first();

        $amount = $available?->amount
            ?? $referral->rewardEntries()->where('status', ReferralRewardEntry::STATUS_PENDING)->value('amount');

        return DB::transaction(function () use ($referral, $actor, $reason, $available, $amount) {
            $entry = ReferralRewardEntry::create([
                'referral_id' => $referral->id,
                'referrer_user_id' => $referral->referrer_user_id,
                'invitee_user_id' => $referral->invitee_user_id,
                'entry_type' => 'void',
                'status' => ReferralRewardEntry::STATUS_VOIDED,
                'amount' => $amount,
                'delta_amount' => $available ? -1 * (float) $available->amount : 0,
                'currency' => $available?->currency ?? config('roicard.referrals.currency', 'GHS'),
                'note' => $reason,
                'created_by' => $actor?->id,
            ]);

            $referral->forceFill([
                'status' => Referral::STATUS_VOIDED,
                'voided_at' => now(),
            ])->save();

            app(AnalyticsService::class)->record($referral->referrer_user_id, 'referral_reward_voided', [
                'referral_id' => $referral->id,
                'amount' => $amount,
            ]);

            return $entry;
        });
    }

    public function markPaid(Referral $referral, User $actor, string $note): ReferralRewardEntry
    {
        if (! config('roicard.referrals.payout_enabled', false)) {
            throw ValidationException::withMessages([
                'referral' => 'Reward redemption is not configured yet.',
            ]);
        }

        $this->assertRefundWindowElapsed($referral);

        $available = $referral->rewardEntries()
            ->where('status', ReferralRewardEntry::STATUS_AVAILABLE)
            ->first();

        if (! $available) {
            throw ValidationException::withMessages(['referral' => 'This reward is not available.']);
        }

        if ($referral->rewardEntries()->where('status', ReferralRewardEntry::STATUS_PAID)->exists()) {
            throw ValidationException::withMessages(['referral' => 'This reward has already been paid.']);
        }

        return DB::transaction(function () use ($referral, $actor, $note, $available) {
            $entry = ReferralRewardEntry::create([
                'referral_id' => $referral->id,
                'referrer_user_id' => $referral->referrer_user_id,
                'invitee_user_id' => $referral->invitee_user_id,
                'entry_type' => 'payout_adjustment',
                'status' => ReferralRewardEntry::STATUS_PAID,
                'amount' => $available->amount,
                'delta_amount' => -1 * (float) $available->amount,
                'currency' => $available->currency,
                'note' => $note,
                'created_by' => $actor->id,
            ]);

            $referral->forceFill(['status' => Referral::STATUS_PAID])->save();

            app(AnalyticsService::class)->record($referral->referrer_user_id, 'referral_reward_paid', [
                'referral_id' => $referral->id,
                'amount' => $entry->amount,
            ]);

            $referral->referrer?->notify(new ReferralRewardPaidNotification($entry));

            return $entry;
        });
    }

    public function voidForInvitee(User $invitee, string $reason): void
    {
        $referral = Referral::where('invitee_user_id', $invitee->id)->first();

        if ($referral && in_array($referral->status, [
            Referral::STATUS_QUALIFIED,
            Referral::STATUS_AVAILABLE,
        ], true)) {
            $this->void($referral, null, $reason);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $code = $this->codeFor($user);
        $referrals = $user->referralsMade()
            ->with(['invitee:id,first_name,last_name,email,created_at', 'rewardEntries'])
            ->latest()
            ->get();

        $entries = $user->referralRewardEntries()->latest()->get();
        $currentEntries = $referrals
            ->map(fn (Referral $referral) => $this->currentRewardEntry($referral))
            ->filter()
            ->values();
        $balance = (float) $entries->sum('delta_amount');
        $pending = (float) $currentEntries->where('status', ReferralRewardEntry::STATUS_PENDING)->sum('amount');
        $available = (float) $currentEntries->where('status', ReferralRewardEntry::STATUS_AVAILABLE)->sum('amount');
        $paid = (float) $currentEntries->where('status', ReferralRewardEntry::STATUS_PAID)->sum('amount');
        $qualificationTrigger = config('roicard.referrals.qualification_trigger');
        $rewardAmount = config('roicard.referrals.reward_amount');

        return [
            'enabled' => (bool) config('roicard.referrals.enabled', false),
            'configured' => (bool) config('roicard.referrals.enabled', false)
                && in_array($qualificationTrigger, self::SUPPORTED_TRIGGERS, true)
                && $rewardAmount !== null
                && $rewardAmount !== '',
            'attribution_days' => (int) config('roicard.referrals.attribution_days', 30),
            'qualification_trigger' => $qualificationTrigger,
            'supported_triggers' => self::SUPPORTED_TRIGGERS,
            'refund_window_days' => config('roicard.referrals.refund_window_days'),
            'signature_referral_enabled' => (bool) $user->referral_signature_enabled,
            'code' => $code,
            'link' => $this->linkFor($user),
            'qr_svg' => $this->qrSvgFor($user),
            'reward' => [
                'amount' => config('roicard.referrals.reward_amount'),
                'currency' => config('roicard.referrals.currency', 'GHS'),
                'payout_enabled' => (bool) config('roicard.referrals.payout_enabled', false),
            ],
            'counts' => [
                'attributed' => $referrals->where('status', Referral::STATUS_ATTRIBUTED)->count(),
                'qualified' => $referrals->where('status', Referral::STATUS_QUALIFIED)->count(),
                'available' => $referrals->where('status', Referral::STATUS_AVAILABLE)->count(),
                'paid' => $referrals->where('status', Referral::STATUS_PAID)->count(),
                'voided' => $referrals->where('status', Referral::STATUS_VOIDED)->count(),
                'expired' => $referrals->where('status', Referral::STATUS_EXPIRED)->count(),
                'capped' => $referrals->where('status', Referral::STATUS_CAPPED)->count(),
            ],
            'balance' => [
                'pending' => $pending,
                'available' => max(0.0, $available),
                'paid' => $paid,
                'ledger_balance' => $balance,
                'currency' => config('roicard.referrals.currency', 'GHS'),
            ],
            'referrals' => $referrals->map(fn (Referral $referral) => [
                'id' => $referral->id,
                'status' => $referral->status,
                'attributed_at' => $referral->attributed_at,
                'expires_at' => $referral->expires_at,
                'qualified_at' => $referral->qualified_at,
                'invitee' => $referral->invitee?->only(['first_name', 'last_name', 'email', 'created_at']),
                'reward_entries' => $referral->rewardEntries->map(fn ($entry) => [
                    'id' => $entry->id,
                    'status' => $entry->status,
                    'entry_type' => $entry->entry_type,
                    'amount' => $entry->amount,
                    'delta_amount' => $entry->delta_amount,
                    'currency' => $entry->currency,
                    'created_at' => $entry->created_at,
                ])->values(),
            ])->values(),
            'ledger' => $entries->map(fn ($entry) => [
                'id' => $entry->id,
                'referral_id' => $entry->referral_id,
                'status' => $entry->status,
                'entry_type' => $entry->entry_type,
                'amount' => $entry->amount,
                'delta_amount' => $entry->delta_amount,
                'currency' => $entry->currency,
                'note' => $entry->note,
                'created_at' => $entry->created_at,
            ])->values(),
        ];
    }

    public function rewardUsageCount(User $referrer, bool $thisMonthOnly = false): int
    {
        return Referral::query()
            ->where('referrer_user_id', $referrer->id)
            ->whereIn('status', [
                Referral::STATUS_QUALIFIED,
                Referral::STATUS_AVAILABLE,
                Referral::STATUS_PAID,
            ])
            ->when($thisMonthOnly, fn ($query) => $query->where('qualified_at', '>=', now()->startOfMonth()))
            ->whereHas('rewardEntries')
            ->count();
    }

    public function committedRewardValue(): float
    {
        return (float) ReferralRewardEntry::query()
            ->get()
            ->groupBy('referral_id')
            ->map(fn (Collection $entries) => $entries
                ->sortByDesc(fn (ReferralRewardEntry $entry) => $this->rewardStatusRank((string) $entry->status))
                ->first())
            ->filter()
            ->whereIn('status', [
                ReferralRewardEntry::STATUS_PENDING,
                ReferralRewardEntry::STATUS_AVAILABLE,
                ReferralRewardEntry::STATUS_PAID,
            ])
            ->sum('amount');
    }

    /**
     * Current reward state per referral for admin totals. Historical ledger
     * rows remain untouched; prior pending/available rows are not double-counted.
     *
     * @return array{pending: int, available: int, paid: int, voided: int, available_value: float, paid_value: float, committed_value: float}
     */
    public function ledgerSummary(): array
    {
        $current = ReferralRewardEntry::query()
            ->get()
            ->groupBy('referral_id')
            ->map(fn (Collection $entries) => $entries
                ->sortByDesc(fn (ReferralRewardEntry $entry) => $this->rewardStatusRank((string) $entry->status))
                ->first())
            ->filter();

        return [
            'pending' => $current->where('status', ReferralRewardEntry::STATUS_PENDING)->count(),
            'available' => $current->where('status', ReferralRewardEntry::STATUS_AVAILABLE)->count(),
            'paid' => $current->where('status', ReferralRewardEntry::STATUS_PAID)->count(),
            'voided' => $current->where('status', ReferralRewardEntry::STATUS_VOIDED)->count(),
            'available_value' => (float) $current
                ->where('status', ReferralRewardEntry::STATUS_AVAILABLE)
                ->sum('amount'),
            'paid_value' => (float) $current
                ->where('status', ReferralRewardEntry::STATUS_PAID)
                ->sum('amount'),
            'committed_value' => (float) $current
                ->whereIn('status', [
                    ReferralRewardEntry::STATUS_PENDING,
                    ReferralRewardEntry::STATUS_AVAILABLE,
                    ReferralRewardEntry::STATUS_PAID,
                ])
                ->sum('amount'),
        ];
    }

    public function expireStaleAttributions(): int
    {
        return Referral::query()
            ->where('status', Referral::STATUS_ATTRIBUTED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => Referral::STATUS_EXPIRED]);
    }

    public function currentRewardEntry(Referral $referral): ?ReferralRewardEntry
    {
        $entries = $referral->relationLoaded('rewardEntries')
            ? $referral->rewardEntries
            : $referral->rewardEntries()->get();

        return $entries
            ->sortByDesc(fn (ReferralRewardEntry $entry) => $this->rewardStatusRank((string) $entry->status))
            ->first();
    }

    protected function rewardStatusRank(string $status): int
    {
        return match ($status) {
            ReferralRewardEntry::STATUS_PAID => 4,
            ReferralRewardEntry::STATUS_VOIDED => 3,
            ReferralRewardEntry::STATUS_AVAILABLE => 2,
            ReferralRewardEntry::STATUS_PENDING => 1,
            default => 0,
        };
    }

    /**
     * @return list<string>
     */
    protected function qualificationRiskFlags(Referral $referral, User $invitee): array
    {
        $referrer = $referral->referrer;
        $flags = [];

        if (! $referrer) {
            return ['missing_referrer'];
        }

        if (mb_strtolower((string) $referrer->email) === mb_strtolower((string) $invitee->email)) {
            $flags[] = 'same_email';
        }

        if (config('roicard.referrals.same_ip_block', false)
            && $referral->attribution_ip !== null
            && $referrer->registration_ip !== null
            && $referral->attribution_ip === $referrer->registration_ip) {
            $flags[] = 'same_ip_as_referrer';
        }

        $sharedPhones = array_intersect(
            $this->profilePhones($referrer),
            $this->profilePhones($invitee),
        );

        if ($sharedPhones !== []) {
            $flags[] = 'same_phone';
        }

        $sharedPaymentMethods = array_intersect(
            $this->paymentMethods($referrer),
            $this->paymentMethods($invitee),
        );

        if ($sharedPaymentMethods !== []) {
            $flags[] = 'same_payment_method';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @return list<string>
     */
    protected function profilePhones(User $user): array
    {
        $phones = $user->profiles()
            ->get(['phone', 'whatsapp_phone'])
            ->flatMap(fn ($profile) => [$profile->phone, $profile->whatsapp_phone])
            ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '')
            ->values()
            ->all();

        return array_values(array_unique(array_filter(array_map(
            fn (string $phone) => $this->normalisePhone($phone),
            $phones,
        ))));
    }

    /**
     * @return list<string>
     */
    protected function paymentMethods(User $user): array
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('status', Payment::STATUS_SUCCESS)
            ->whereNotNull('momo_number')
            ->pluck('momo_number')
            ->map(fn ($value) => $this->normalisePhone((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function normalisePhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 7 ? $digits : null;
    }

    protected function assertRefundWindowElapsed(Referral $referral): void
    {
        $days = config('roicard.referrals.refund_window_days');

        if ($days === null || $days === '') {
            return;
        }

        $start = $referral->qualified_at ?? $referral->attributed_at;

        if ($start !== null && $start->copy()->addDays(max(0, (int) $days))->isFuture()) {
            throw ValidationException::withMessages([
                'referral' => 'This reward cannot be released until the configured refund window has passed.',
            ]);
        }
    }

    protected function maybeAlertBudget(): void
    {
        $threshold = config('roicard.referrals.budget_alert_threshold');

        if ($threshold === null || $threshold === '') {
            return;
        }

        $committed = $this->committedRewardValue();

        if ($committed < (float) $threshold) {
            return;
        }

        $cacheKey = 'referral:budget-alert:' . now()->format('Y-m');

        if (! Cache::add($cacheKey, true, now()->endOfMonth())) {
            return;
        }

        Log::warning('referral.budget_alert', [
            'month' => now()->format('Y-m'),
            'committed_value' => $committed,
            'threshold' => (float) $threshold,
        ]);

        app(AdminAlertService::class)->send(
            NotificationType::ADMIN_REFERRAL_REWARD_CAP,
            'Referral reward budget threshold reached',
            'Committed referral rewards reached the configured alert threshold.',
            [
                'reason' => 'budget_alert_threshold',
                'committed_value' => $committed,
                'threshold' => (float) $threshold,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function alertReferralCap(Referral $referral, string $reason, array $context = []): void
    {
        app(AdminAlertService::class)->send(
            NotificationType::ADMIN_REFERRAL_REWARD_CAP,
            'Referral reward cap reached',
            'A referral was capped before a reward could be issued.',
            array_merge($context, [
                'reason' => $reason,
                'referral_id' => $referral->id,
                'referrer_user_id' => $referral->referrer_user_id,
            ]),
        );
    }

    /**
     * @param  string|list<string>  $flags
     */
    protected function flag(Referral $referral, string|array $flags): void
    {
        $flags = is_array($flags) ? $flags : [$flags];
        $riskFlags = array_values(array_unique([
            ...($referral->risk_flags ?? []),
            ...$flags,
        ]));

        $referral->forceFill([
            'status' => Referral::STATUS_VOIDED,
            'voided_at' => now(),
            'risk_flags' => $riskFlags,
        ])->save();

        app(AnalyticsService::class)->record($referral->referrer_user_id, 'referral_risk_blocked', [
            'referral_id' => $referral->id,
            'risk_flags' => $riskFlags,
        ]);
    }
}
