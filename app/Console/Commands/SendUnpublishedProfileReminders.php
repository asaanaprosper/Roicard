<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Notifications\UnpublishedProfileReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendUnpublishedProfileReminders extends Command
{
    protected $signature = 'notifications:unpublished-profile-reminders';

    protected $description = 'Remind members whose profile has stayed unpublished past the configured delay';

    public function handle(): int
    {
        $days = config('roicard.notifications.unpublished_profile_reminder_days');

        if ($days === null || $days === '') {
            $this->line('Unpublished-profile reminder delay is not configured; skipped.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays(max(0, (int) $days));
        $sent = 0;

        Profile::query()
            ->where('is_published', false)
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('user', function ($user) {
                $user->whereNotNull('onboarding_completed_at')
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at');
            })
            ->with('user')
            ->chunkById(100, function ($profiles) use (&$sent) {
                foreach ($profiles as $profile) {
                    $user = $profile->user;

                    if (! $user) {
                        continue;
                    }

                    $cacheKey = 'notifications:unpublished-profile:' . $profile->id . ':' . $profile->updated_at?->timestamp;

                    if (! Cache::add($cacheKey, true, now()->addDays(30))) {
                        continue;
                    }

                    $user->notify(new UnpublishedProfileReminderNotification($profile));
                    $sent++;
                }
            });

        $this->info("Unpublished-profile reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
