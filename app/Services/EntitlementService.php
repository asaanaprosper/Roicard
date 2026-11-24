<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanLimitKey;
use InvalidArgumentException;

/**
 * Centralized plan/entitlement lookup and enforcement.
 *
 * Resolution order:
 *   1. the user's active assigned plan and its plan_entitlements row;
 *   2. the default active plan, then the configured default plan key;
 *   3. config/roicard.php plans.limits (NULL = unlimited).
 *
 * Feature code never hardcodes a limit; it asks this service.
 */
class EntitlementService
{
    public function planFor(User $user): ?Plan
    {
        $user->loadMissing('plan');

        if ($user->plan && $user->plan->is_active) {
            return $user->plan;
        }

        $default = Plan::query()->active()->where('is_default', true)->first()
            ?? Plan::query()->active()
                ->where('key', (string) config('roicard.plans.default_key', 'free'))
                ->first();

        if ($default) {
            $default->loadMissing('entitlements');
        }

        return $default;
    }

    /**
     * NULL means unlimited. Zero means disabled.
     */
    public function limit(User $user, string $key): ?int
    {
        $this->assertValidKey($key);

        $plan = $this->planFor($user);

        // Historical paid members keep the configured legacy access unless an
        // administrator explicitly assigns a non-default plan.
        $assigned = $user->plan_id ? $user->plan : null;
        $isDefaultPlan = $plan && $plan->key === (string) config('roicard.plans.default_key', 'free');

        if ($user->legacy_member && ($assigned === null || $isDefaultPlan)) {
            return $this->configLimit($key);
        }

        if ($plan) {
            $entitlement = $plan->entitlements->firstWhere('limit_key', $key);
            if ($entitlement) {
                return $entitlement->limit_value;
            }
        }

        return $this->configLimit($key);
    }

    public function configLimit(string $key): ?int
    {
        $this->assertValidKey($key);

        $value = config('roicard.plans.limits.' . $key);

        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    public function allows(User $user, string $key, int $currentUsage = 0, int $increment = 1): bool
    {
        $limit = $this->limit($user, $key);

        if ($limit === null) {
            return true;
        }

        return ($currentUsage + $increment) <= $limit;
    }

    public function remaining(User $user, string $key, int $currentUsage = 0): ?int
    {
        $limit = $this->limit($user, $key);

        return $limit === null ? null : max(0, $limit - $currentUsage);
    }

    /**
     * @throws \App\Exceptions\EntitlementExceededException
     */
    public function ensureWithinLimit(
        User $user,
        string $key,
        int $currentUsage = 0,
        int $increment = 1,
        ?string $message = null,
    ): void {
        $limit = $this->limit($user, $key);

        if ($limit === null || ($currentUsage + $increment) <= $limit) {
            return;
        }

        throw new \App\Exceptions\EntitlementExceededException(
            $key,
            $limit,
            $currentUsage,
            $message,
        );
    }

    /**
     * @return array<string, int|null>
     */
    public function allLimits(User $user): array
    {
        $limits = [];
        foreach (PlanLimitKey::all() as $key) {
            $limits[$key] = $this->limit($user, $key);
        }

        return $limits;
    }

    public function assignPlan(User $user, ?Plan $plan): void
    {
        $user->forceFill(['plan_id' => $plan?->id])->save();
    }

    /**
     * The mark is enabled only by the assigned plan's explicit configuration.
     * It is removed when a subscription lapses/expires because the billing
     * service then returns the member to the Free plan.
     */
    public function hasVerificationMark(User $user): bool
    {
        if ($user->isDeactivated() || ! $user->isActive()) {
            return false;
        }

        $plan = $this->planFor($user);

        if (! $plan || ! $plan->isPaid()) {
            return false;
        }

        // Section 9.2 exposes the mark as a named limit key. When a plan has
        // an explicit VERIFICATION_MARK row it is authoritative (0 withholds
        // the mark, NULL/positive allows it). The legacy plan flag remains the
        // fallback for existing plans that do not define the key yet.
        $hasExplicitMarkEntitlement = $plan->entitlements
            ->contains('limit_key', PlanLimitKey::VERIFICATION_MARK);
        $markLimit = $this->limit($user, PlanLimitKey::VERIFICATION_MARK);
        $planAllowsMark = $hasExplicitMarkEntitlement
            ? $markLimit === null || $markLimit > 0
            : (bool) $plan->verification_mark;

        if (! $planAllowsMark) {
            return false;
        }

        $subscription = $user->relationLoaded('subscriptions')
            ? $user->subscriptions->sortByDesc('id')->first()
            : $user->subscriptions()->latest('id')->first();

        if ($subscription) {
            $end = $subscription->current_period_end;

            if (in_array($subscription->status, [Subscription::STATUS_EXPIRED, Subscription::STATUS_CANCELLED], true)
                && $end !== null && $end->isPast()) {
                return false;
            }
        }

        return true;
    }

    public function verificationLabel(): string
    {
        return 'Verified Roicard member, email and phone confirmed.';
    }

    /**
     * Replace a plan's entitlement map. A NULL value means unlimited.
     *
     * @param  array<string, int|string|null>  $entitlements
     */
    public function syncEntitlements(Plan $plan, array $entitlements): void
    {
        foreach ($entitlements as $key => $value) {
            $this->assertValidKey((string) $key);

            PlanEntitlement::updateOrCreate(
                ['plan_id' => $plan->id, 'limit_key' => (string) $key],
                ['limit_value' => $value === null || $value === '' ? null : max(0, (int) $value)],
            );
        }

        // Remove keys deliberately omitted from the submitted map so the
        // plan falls back to the configured default for those keys.
        $plan->entitlements()
            ->whereNotIn('limit_key', array_keys($entitlements))
            ->delete();

        $plan->unsetRelation('entitlements');
    }

    protected function assertValidKey(string $key): void
    {
        if (! PlanLimitKey::isValid($key)) {
            throw new InvalidArgumentException("Unknown plan limit key [{$key}].");
        }
    }
}
