<?php

namespace App\Notifications;

use App\Models\ReferralRewardEntry;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class ReferralRewardCreditedNotification extends MemberNotification
{
    public function __construct(public ReferralRewardEntry $reward) {}

    protected function notificationType(): string
    {
        return NotificationType::REFERRAL_REWARD_CREDITED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/referrals';

        $message = (new MailMessage)
            ->subject('Referral reward credited')
            ->line('A referral reward is now available in your ledger.')
            ->line('Amount: ' . $this->reward->currency . ' ' . number_format((float) $this->reward->amount, 2))
            ->action('View reward', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::REFERRAL_REWARD_CREDITED,
            'reward_entry_id' => $this->reward->id,
            'amount' => $this->reward->amount,
            'currency' => $this->reward->currency,
            'title' => 'Referral reward credited',
            'body' => 'A referral reward is available in your ledger.',
        ];
    }
}
