<?php

namespace App\Notifications;

use App\Models\Profile;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class UnpublishedProfileReminderNotification extends MemberNotification
{
    public function __construct(public Profile $profile) {}

    protected function notificationType(): string
    {
        return NotificationType::UNPUBLISHED_PROFILE_REMINDER;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/dashboard/profile?profile_id=' . $this->profile->id;
        $name = $this->profile->display_name ?: $this->profile->slug ?: 'your profile';

        $message = (new MailMessage)
            ->subject('Your Roicard profile is not published')
            ->line("{$name} is currently unpublished, so it is not visible to the network.")
            ->line('Review the profile and publish it when you are ready.')
            ->action('Review profile', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::UNPUBLISHED_PROFILE_REMINDER,
            'profile_id' => $this->profile->id,
            'title' => 'Your profile is unpublished',
            'body' => 'Publish your profile when you are ready for the network to see it.',
        ];
    }

    public function toPush(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
