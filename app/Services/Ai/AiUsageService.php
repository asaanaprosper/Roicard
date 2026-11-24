<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\EntitlementUsageService;
use App\Services\Notifications\AdminAlertService;
use App\Support\NotificationType;
use App\Support\PlanLimitKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily AI quota and token/cost accounting.
 *
 * Contract:
 *  - availability is checked before any work;
 *  - the daily counter is consumed exactly once, immediately before a real
 *    provider request;
 *  - provider failures still consume that request because they can incur cost;
 *  - no prompt/completion text is ever stored or logged.
 */
class AiUsageService
{
    public function __construct(
        protected EntitlementService $entitlements,
        protected EntitlementUsageService $usage,
    ) {}

    public function limit(User $user): ?int
    {
        return $this->entitlements->limit($user, PlanLimitKey::AI_CALLS_PER_DAY);
    }

    public function usedToday(User $user): int
    {
        return $this->usage->used($user, PlanLimitKey::AI_CALLS_PER_DAY);
    }

    public function remainingToday(User $user): ?int
    {
        return $this->entitlements->remaining(
            $user,
            PlanLimitKey::AI_CALLS_PER_DAY,
            $this->usedToday($user),
        );
    }

    /**
     * @throws \App\Exceptions\EntitlementExceededException
     */
    public function ensureAvailable(User $user): void
    {
        $this->entitlements->ensureWithinLimit(
            $user,
            PlanLimitKey::AI_CALLS_PER_DAY,
            $this->usedToday($user),
            1,
            'Your plan has reached its daily AI limit. You can continue manually or try again tomorrow.',
        );
    }

    /**
     * Consume one call. Call only once, immediately before the provider call.
     *
     * @throws \App\Exceptions\EntitlementExceededException
     */
    public function consume(User $user): void
    {
        $this->usage->consume($user, PlanLimitKey::AI_CALLS_PER_DAY, $this->entitlements);
    }

    /**
     * @param  array{input_tokens?: int, output_tokens?: int, total_tokens?: int}  $usage
     */
    public function record(
        User $user,
        string $feature,
        ?string $model,
        array $usage,
        string $outcome,
        ?string $errorCode = null,
    ): AiUsageLog {
        $input = max(0, (int) ($usage['input_tokens'] ?? 0));
        $output = max(0, (int) ($usage['output_tokens'] ?? 0));
        $total = max($input + $output, (int) ($usage['total_tokens'] ?? 0));

        $log = AiUsageLog::create([
            'user_id' => $user->id,
            'feature' => $feature,
            'provider' => (string) config('roicard.ai.provider', 'gemini'),
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'estimated_cost_usd' => $this->estimateCost($input, $output),
        ]);

        // Metadata only: member, feature, tokens, outcome. Never prompt text.
        Log::info('ai.call', [
            'member_id' => (int) $user->id,
            'feature' => $feature,
            'provider' => $log->provider,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'outcome' => $outcome,
            'error_code' => $errorCode,
        ]);

        $this->maybeAlertMonthlyCost();

        return $log;
    }

    public function estimateCost(int $inputTokens, int $outputTokens): ?float
    {
        $inputPrice = config('roicard.ai.input_price_per_million_usd');
        $outputPrice = config('roicard.ai.output_price_per_million_usd');

        if ($inputPrice === null || $inputPrice === '' || $outputPrice === null || $outputPrice === '') {
            return null;
        }

        $cost = ($inputTokens / 1_000_000) * (float) $inputPrice
            + ($outputTokens / 1_000_000) * (float) $outputPrice;

        return round($cost, 6);
    }

    public function monthlyEstimatedCost(): float
    {
        return round((float) AiUsageLog::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd'), 6);
    }

    public function monthlyAlertThreshold(): ?float
    {
        $threshold = config('roicard.ai.monthly_cost_alert_usd');

        if ($threshold === null || $threshold === '') {
            return null;
        }

        return (float) $threshold;
    }

    public function costAlertReached(): bool
    {
        $threshold = $this->monthlyAlertThreshold();

        return $threshold !== null && $this->monthlyEstimatedCost() >= $threshold;
    }

    /**
     * Fire once per calendar month when the configured threshold is reached.
     * The warning contains no prompt content and no member identifiers beyond
     * the aggregate cost.
     */
    public function maybeAlertMonthlyCost(): void
    {
        $threshold = $this->monthlyAlertThreshold();

        if ($threshold === null) {
            return;
        }

        $estimated = $this->monthlyEstimatedCost();

        if ($estimated < $threshold) {
            return;
        }

        $cacheKey = 'ai:cost-alert:' . now()->format('Y-m');

        if (! Cache::add($cacheKey, true, now()->endOfMonth())) {
            return;
        }

        Log::warning('ai.monthly_cost_alert', [
            'month' => now()->format('Y-m'),
            'estimated_cost_usd' => $estimated,
            'threshold_usd' => $threshold,
        ]);

        app(AdminAlertService::class)->send(
            NotificationType::ADMIN_AI_BUDGET,
            'AI monthly budget threshold reached',
            'Estimated monthly AI cost reached the configured alert threshold.',
            [
                'month' => now()->format('Y-m'),
                'estimated_cost_usd' => $estimated,
                'threshold_usd' => $threshold,
            ],
        );
    }

    /**
     * @return array{daily_limit: int|null, used_today: int, remaining_today: int|null, monthly_estimated_cost_usd: float, monthly_cost_alert_usd: float|null, cost_alert_reached: bool}
     */
    public function status(User $user): array
    {
        return [
            'daily_limit' => $this->limit($user),
            'used_today' => $this->usedToday($user),
            'remaining_today' => $this->remainingToday($user),
            'monthly_estimated_cost_usd' => $this->monthlyEstimatedCost(),
            'monthly_cost_alert_usd' => $this->monthlyAlertThreshold(),
            'cost_alert_reached' => $this->costAlertReached(),
        ];
    }
}
