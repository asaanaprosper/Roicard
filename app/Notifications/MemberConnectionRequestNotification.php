<?php

namespace App\Notifications;

use App\Models\Connection;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberConnectionRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public Connection $memberConnection) {}

    protected function notificationType(): string
    {
        return NotificationType::CONNECTION_REQUEST;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim((string) $this->memberConnection->guest_name) ?: 'A Roicard member';
        $url = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/dashboard/connections/requests';

        $message = (new MailMessage)
            ->subject('New connection request on Roicard')
            ->line("{$name} would like to connect with you.");

        if ($this->memberConnection->member_note) {
            $message->line('Note: ' . $this->memberConnection->member_note);
        }
        if ($this->memberConnection->match_reason) {
            $message->line('Why this may be relevant: ' . $this->memberConnection->match_reason);
        }

        return $message->action('Review request', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'member_connection_request',
            'connection_id' => $this->memberConnection->id,
            'sender_profile_id' => $this->memberConnection->sender_profile_id,
            'note' => $this->memberConnection->member_note,
            'match_reason' => $this->memberConnection->match_reason,
            'title' => 'New Connection Request',
            'body' => "{$this->memberConnection->guest_name} wants to connect with you.",
        ];
    }
}
