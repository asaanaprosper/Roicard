<?php

namespace App\Notifications;

use App\Models\Connection;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConnectionRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(
        public Connection $connectionData
    ) {}

    protected function notificationType(): string
    {
        return NotificationType::CONNECTION_REQUEST;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $connection = $this->connectionData;

        return (new MailMessage)
            ->subject('New connection request on Roicard')
            ->markdown('emails.notifications.connection-request', [
                'connection' => $connection,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'connection_request',
            'connection_id' => $this->connectionData->id,
            'guest_name' => $this->connectionData->guest_name,
            'guest_email' => $this->connectionData->guest_email,
            'guest_org' => $this->connectionData->guest_org,
            'title' => 'New Connection Request',
            'body' => "{$this->connectionData->guest_name} wants to connect with you.",
        ];
    }
}
