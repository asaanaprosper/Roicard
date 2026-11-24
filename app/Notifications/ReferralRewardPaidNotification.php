<?php

namespace App\Notifications;

use App\Models\ReferralRewardEntry;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class ReferralRewardPaidNotification extends MemberNotification
{
    public function __construct(public ReferralRewardEntry $reward) {}

    protected function notificationType(): string
    {
        return NotificationType::REFERRAL_REWARD_PAID;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/referrals';

        $message = (new MailMessage)
            ->subject('Referral reward paid')
            ->line('A referral reward has been marked paid.')
            ->line('Amount: ' . $this->reward->currency . ' ' . number_format((float) $this->reward->amount, 2))
            ->action('View reward history', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::REFERRAL_REWARD_PAID,
            'reward_entry_id' => $this->reward->id,
            'amount' => $this->reward->amount,
            'currency' => $this->reward->currency,
            'title' => 'Referral reward paid',
            'body' => 'A referral reward has been marked paid.',
        ];
    }
}
