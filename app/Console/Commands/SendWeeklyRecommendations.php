<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Notifications\WeeklyRecommendationsNotification;
use App\Services\MatchmakingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendWeeklyRecommendations extends Command
{
    protected $signature = 'notifications:weekly-recommendations {--chunk=100 : Profiles processed per batch}';

    protected $description = 'Send the weekly recommendations digest';

    public function handle(MatchmakingService $matchmaking): int
    {
        $sent = 0;
        $skipped = 0;
        $chunk = max(1, (int) $this->option('chunk'));
        $week = now()->format('o-W');

        Profile::query()
            ->where('is_published', true)
            ->whereHas('signals', fn ($signals) => $signals->where('is_active', true))
            ->with('user')
            ->chunkById($chunk, function ($profiles) use ($matchmaking, $week, &$sent, &$skipped) {
                foreach ($profiles as $profile) {
                    $user = $profile->user;

                    if (! $user || $user->isDeactivated() || $user->trashed()) {
                        $skipped++;
                        continue;
                    }

                    $cacheKey = "notifications:weekly-recommendations:{$profile->id}:{$week}";

                    if (! Cache::add($cacheKey, true, now()->addDays(8))) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $payload = $matchmaking->warmRecommendations($user, $profile);
                        $items = $payload['items'] ?? [];

                        if ($items === []) {
                            $skipped++;
                            continue;
                        }

                        $user->notify(new WeeklyRecommendationsNotification($profile, $items));
                        $sent++;
                    } catch (\Throwable $e) {
                        report($e);
                        $skipped++;
                    }
                }
            });

        $this->info("Weekly recommendations sent: {$sent}; skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
