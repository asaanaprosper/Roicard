<?php

namespace App\Notifications;

use App\Models\Referral;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class ReferralSignupNotification extends MemberNotification
{
    public function __construct(public Referral $referral) {}

    protected function notificationType(): string
    {
        return NotificationType::REFERRAL_SIGNUP;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/referrals';
        $name = trim(($this->referral->invitee?->first_name ?? '') . ' ' . ($this->referral->invitee?->last_name ?? '')) ?: 'Someone';

        $message = (new MailMessage)
            ->subject('Someone joined Roicard with your referral')
            ->line("{$name} registered using your referral link.")
            ->line('Your referral is being tracked. You will be notified if it qualifies.')
            ->action('View referrals', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::REFERRAL_SIGNUP,
            'referral_id' => $this->referral->id,
            'title' => 'New referral signup',
            'body' => 'Someone registered with your referral link.',
        ];
    }
}
