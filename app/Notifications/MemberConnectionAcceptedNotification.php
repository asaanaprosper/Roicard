<?php

namespace App\Notifications;

use App\Models\Connection;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberConnectionAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public Connection $memberConnection) {}

    protected function notificationType(): string
    {
        return NotificationType::CONNECTION_ACCEPTED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/dashboard/connections';

        return (new MailMessage)
            ->subject('Your connection request was accepted')
            ->line('Your Roicard connection request was accepted. You can now view the connection in your dashboard.')
            ->action('View connections', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'member_connection_accepted',
            'connection_id' => $this->memberConnection->id,
            'title' => 'Connection Accepted',
            'body' => 'Your connection request was accepted.',
        ];
    }
}
