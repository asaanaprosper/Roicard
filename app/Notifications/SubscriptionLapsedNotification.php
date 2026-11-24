<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class SubscriptionLapsedNotification extends MemberNotification
{
    public function __construct(public Subscription $subscription) {}

    protected function notificationType(): string
    {
        return NotificationType::SUBSCRIPTION_LAPSED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/billing';

        $message = (new MailMessage)
            ->subject('Your Roicard plan has lapsed')
            ->line('Your paid plan period has ended and the account is now on Free limits.')
            ->line('Your profile and data have not been deleted.')
            ->action('View billing', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::SUBSCRIPTION_LAPSED,
            'subscription_id' => $this->subscription->id,
            'title' => 'Your plan has lapsed',
            'body' => 'The account has moved to Free limits. Your data is retained.',
        ];
    }

    public function toPush(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
