<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Support\NotificationType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Single place that decides whether a notification may be delivered and on
 * which channels. Missing user preferences fall back to the Appendix C
 * catalogue defaults. The daily cap is disabled until
 * NOTIFICATION_DAILY_CAP is configured.
 */
class NotificationPreferenceService
{
    /**
     * @return array<string, array<string, bool>>
     */
    public function preferences(User $user): array
    {
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        $preferences = [];

        foreach (NotificationType::all() as $type => $definition) {
            $channels = [];

            foreach ($definition['channels'] as $channel) {
                $channels[$channel] = (bool) ($stored[$type][$channel] ?? true);
            }

            $preferences[$type] = $channels;
        }

        return $preferences;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(User $user): array
    {
        $preferences = $this->preferences($user);
        $types = $user->isAdmin() ? NotificationType::all() : NotificationType::userFacing();

        return [
            'types' => collect($types)
                ->map(fn (array $definition, string $type) => [
                    'type' => $type,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'mandatory' => $definition['mandatory'],
                    'admin' => $definition['admin'],
                    'channels' => $preferences[$type],
                ])
                ->values()
                ->all(),
            'preferences' => collect($preferences)->only(array_keys($types))->all(),
            'daily_cap' => $this->dailyCap(),
            'sent_today' => $this->sentToday($user),
        ];
    }

    /**
     * @param  array<string, array<string, bool>>  $preferences
     * @return array<string, array<string, bool>>
     */
    public function update(User $user, array $preferences): array
    {
        $current = $this->preferences($user);
        $allowedTypes = $user->isAdmin() ? NotificationType::keys() : array_keys(NotificationType::userFacing());

        foreach ($preferences as $type => $channels) {
            if (! in_array($type, $allowedTypes, true) || ! isset($current[$type]) || ! is_array($channels)) {
                continue;
            }

            foreach ($current[$type] as $channel => $_) {
                if (array_key_exists($channel, $channels)) {
                    $current[$type][$channel] = (bool) $channels[$channel];
                }
            }
        }

        $user->forceFill(['notification_preferences' => $current])->save();

        return $this->preferences($user->fresh());
    }

    /**
     * @param  list<string>  $requestedChannels
     * @return list<string>
     */
    public function channelsFor(User $user, string $type, array $requestedChannels): array
    {
        $known = NotificationType::all()[$type] ?? null;

        if ($known === null) {
            // Legacy notifications outside the Appendix C catalogue keep their
            // existing channel behaviour.
            return array_values(array_intersect(
                $requestedChannels,
                [NotificationType::CHANNEL_DATABASE, NotificationType::CHANNEL_MAIL, NotificationType::CHANNEL_PUSH],
            ));
        }

        $preferences = $this->preferences($user)[$type] ?? [];
        $channels = [];

        foreach ($requestedChannels as $channel) {
            if (($preferences[$channel] ?? true) === true) {
                $channels[] = $channel;
            }
        }

        $channels = array_values(array_unique($channels));

        if ($channels === [] || NotificationType::isMandatory($type)) {
            return $channels;
        }

        return $this->reserveWithinDailyCap($user) ? $channels : [];
    }

    public function unsubscribe(User $user, string $type, string $channel): array
    {
        $preferences = $this->preferences($user);

        if (isset($preferences[$type][$channel])) {
            $preferences[$type][$channel] = false;
            $user->forceFill(['notification_preferences' => $preferences])->save();
        }

        return $this->preferences($user->fresh());
    }

    public function unsubscribeUrl(User $user, string $type, string $channel): string
    {
        return URL::temporarySignedRoute(
            'notifications.unsubscribe',
            now()->addDays(30),
            ['user' => $user->getKey(), 'type' => $type, 'channel' => $channel],
        );
    }

    public function dailyCap(): ?int
    {
        $cap = config('roicard.notifications.daily_cap');

        if ($cap === null || $cap === '') {
            return null;
        }

        return max(0, (int) $cap);
    }

    public function sentToday(User $user): int
    {
        return (int) Cache::get($this->dailyKey($user), 0);
    }

    protected function reserveWithinDailyCap(User $user): bool
    {
        $cap = $this->dailyCap();

        if ($cap === null) {
            return true;
        }

        $key = $this->dailyKey($user);
        $sent = (int) Cache::get($key, 0);

        if ($sent >= $cap) {
            return false;
        }

        Cache::put($key, $sent + 1, now()->endOfDay());

        return true;
    }

    protected function dailyKey(User $user): string
    {
        return 'notifications:daily:' . $user->getKey() . ':' . now()->format('Y-m-d');
    }
}
