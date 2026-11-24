<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Notifications\SubscriptionLapseWarningNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendSubscriptionLapseReminders extends Command
{
    protected $signature = 'notifications:subscription-lapse-reminders';

    protected $description = 'Warn members before their paid plan period ends';

    public function handle(): int
    {
        $days = config('roicard.notifications.subscription_lapse_warning_days');

        if ($days === null || $days === '') {
            $this->line('Subscription lapse warning window is not configured; skipped.');

            return self::SUCCESS;
        }

        $until = now()->addDays(max(0, (int) $days));
        $sent = 0;

        Subscription::query()
            ->current()
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now(), $until])
            ->with('user')
            ->chunkById(100, function ($subscriptions) use (&$sent) {
                foreach ($subscriptions as $subscription) {
                    $user = $subscription->user;

                    if (! $user || $user->isDeactivated() || $user->trashed()) {
                        continue;
                    }

                    $cacheKey = 'notifications:subscription-lapse:'
                        . $subscription->id . ':'
                        . $subscription->current_period_end?->timestamp;

                    if (! Cache::add($cacheKey, true, now()->addDays(10))) {
                        continue;
                    }

                    $user->notify(new SubscriptionLapseWarningNotification($subscription));
                    $sent++;
                }
            });

        $this->info("Subscription lapse warnings sent: {$sent}.");

        return self::SUCCESS;
    }
}
