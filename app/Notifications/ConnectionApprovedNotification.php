<?php

namespace App\Notifications;

use App\Models\Connection;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ConnectionApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public Connection $connectionData
    ) {}

    protected function notificationType(): string
    {
        return NotificationType::CONNECTION_ACCEPTED;
    }

    protected function notificationChannels(): array
    {
        return [NotificationType::CHANNEL_DATABASE];
    }

    public function toArray(object $notifiable): array
    {
        $member = $this->connectionData->member;
        $memberName = $member
            ? trim($member->first_name . ' ' . $member->last_name)
            : 'a ROICARD member';

        return [
            'type' => 'connection_approved',
            'connection_id' => $this->connectionData->id,
            'guest_name' => $this->connectionData->guest_name,
            'member_name' => $memberName,
            'title' => 'Connection Request Accepted',
            'body' => "{$memberName} accepted your connection request.",
        ];
    }
}