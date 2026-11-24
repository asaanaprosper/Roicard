<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\MatchmakingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Recomputes matchmaking recommendations for every published profile with at
 * least one active Seeking/Offering item. Warming the cache avoids recording
 * impressions that the member never saw.
 *
 * Run manually:  php artisan recommendations:refresh
 * Scheduled:    daily via routes/console.php (needs `schedule:run` cron).
 */
class RefreshRecommendations extends Command
{
    protected $signature = 'recommendations:refresh {--chunk=100 : Profiles processed per batch}';

    protected $description = 'Recompute and cache matchmaking recommendations daily';

    public function handle(MatchmakingService $matchmaking): int
    {
        // New version invalidates every previously cached payload.
        Cache::increment('matchmaking:version');

        $processed = 0;
        $failed = 0;
        $chunk = max(1, (int) $this->option('chunk'));

        Profile::query()
            ->where('is_published', true)
            ->whereHas('signals', fn ($signals) => $signals->where('is_active', true))
            ->with('user')
            ->chunkById($chunk, function ($profiles) use ($matchmaking, &$processed, &$failed) {
                foreach ($profiles as $profile) {
                    $user = $profile->user;

                    if (! $user || $user->isDeactivated()) {
                        continue;
                    }

                    try {
                        $matchmaking->warmRecommendations($user, $profile);
                        $processed++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        report($exception);
                    }
                }
            });

        $this->info("Refreshed recommendations for {$processed} profile(s).");

        if ($failed > 0) {
            $this->warn("{$failed} profile(s) could not be refreshed.");
        }

        return self::SUCCESS;
    }
}
