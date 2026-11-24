<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use App\Support\NotificationType;

trait RespectsNotificationPreferences
{
    abstract protected function notificationType(): string;

    /**
     * @return list<string>
     */
    protected function notificationChannels(): array
    {
        return NotificationType::channels($this->notificationType());
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return $this->notificationChannels();
        }

        return app(NotificationPreferenceService::class)->channelsFor(
            $notifiable,
            $this->notificationType(),
            $this->notificationChannels(),
        );
    }

    protected function preferencesUrl(User $notifiable, string $channel = NotificationType::CHANNEL_MAIL): string
    {
        return app(NotificationPreferenceService::class)
            ->unsubscribeUrl($notifiable, $this->notificationType(), $channel);
    }
}
