<?php

namespace App\Console\Commands;

use App\Models\AdminActionLog;
use App\Models\AiUsageLog;
use App\Models\RecommendationEvent;
use Illuminate\Console\Command;

/**
 * Deletes records past founder-approved retention windows.
 *
 * Every window is disabled by default. This command never deletes anything
 * until the corresponding RETENTION_* configuration value is set.
 */
class PruneRetentionData extends Command
{
    protected $signature = 'data:prune {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete recommendation, audit and AI records past configured retention windows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targets = [
            'recommendation_event_days' => [RecommendationEvent::class, 'RecommendationEvent'],
            'admin_action_log_days' => [AdminActionLog::class, 'AuditLog'],
            'ai_usage_log_days' => [AiUsageLog::class, 'AI usage log'],
        ];

        foreach ($targets as $key => [$model, $label]) {
            $days = config('roicard.retention.' . $key);

            if ($days === null || $days === '') {
                $this->line("{$label}: retention not configured; retained.");
                continue;
            }

            $cutoff = now()->subDays(max(0, (int) $days));
            $count = $model::query()->where('created_at', '<', $cutoff)->count();

            if ($dryRun) {
                $this->line("{$label}: {$count} record(s) older than {$days} day(s) would be deleted.");
                continue;
            }

            $deleted = $model::query()->where('created_at', '<', $cutoff)->delete();
            $this->line("{$label}: {$deleted} record(s) deleted.");
        }

        if ($dryRun) {
            $this->comment('Dry run only; no records were changed.');
        }

        return self::SUCCESS;
    }
}
