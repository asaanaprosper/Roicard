<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class SubscriptionLapseWarningNotification extends MemberNotification
{
    public function __construct(public Subscription $subscription) {}

    protected function notificationType(): string
    {
        return NotificationType::SUBSCRIPTION_ABOUT_TO_LAPSE;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/billing';
        $end = $this->subscription->current_period_end?->toFormattedDateString() ?? 'the end of the current period';

        $message = (new MailMessage)
            ->subject('Your Roicard plan is about to lapse')
            ->line("Your current plan period ends on {$end}.")
            ->line('Renew before then to keep your paid plan and its features.')
            ->action('Manage billing', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::SUBSCRIPTION_ABOUT_TO_LAPSE,
            'subscription_id' => $this->subscription->id,
            'current_period_end' => $this->subscription->current_period_end?->toIso8601String(),
            'title' => 'Your plan is about to lapse',
            'body' => 'Renew before the period ends to keep your paid plan.',
        ];
    }

    public function toPush(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
