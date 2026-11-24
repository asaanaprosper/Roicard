<?php

namespace App\Services;

use App\Models\EntitlementUsage;
use App\Models\User;

class EntitlementUsageService
{
    public const DAILY_KEYS = [
        \App\Support\PlanLimitKey::SEARCHES_PER_DAY,
        \App\Support\PlanLimitKey::AI_CALLS_PER_DAY,
    ];

    public function periodKey(string $limitKey): string
    {
        return in_array($limitKey, self::DAILY_KEYS, true)
            ? now()->toDateString()
            : now()->format('Y-m');
    }

    public function used(User $user, string $limitKey): int
    {
        return (int) EntitlementUsage::query()
            ->where('user_id', $user->id)
            ->where('limit_key', $limitKey)
            ->where('period_key', $this->periodKey($limitKey))
            ->value('used');
    }

    public function increment(User $user, string $limitKey, int $amount = 1): int
    {
        $period = $this->periodKey($limitKey);

        $usage = EntitlementUsage::firstOrCreate(
            ['user_id' => $user->id, 'limit_key' => $limitKey, 'period_key' => $period],
            ['used' => 0],
        );

        $usage->increment('used', max(1, $amount));

        return (int) $usage->fresh()->used;
    }

    /**
     * Enforce and consume in one step. Call only after the action is ready to
     * execute so a validation failure does not consume quota.
     */
    public function consume(User $user, string $limitKey, EntitlementService $entitlements): void
    {
        $used = $this->used($user, $limitKey);
        $entitlements->ensureWithinLimit(
            $user,
            $limitKey,
            $used,
            1,
            'Your plan has reached its usage limit for this feature.',
        );

        $this->increment($user, $limitKey);
    }
}
