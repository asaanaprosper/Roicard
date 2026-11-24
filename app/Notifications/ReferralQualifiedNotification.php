<?php

namespace App\Notifications;

use App\Models\Referral;
use App\Models\ReferralRewardEntry;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class ReferralQualifiedNotification extends MemberNotification
{
    public function __construct(
        public Referral $referral,
        public ?ReferralRewardEntry $reward = null,
    ) {}

    protected function notificationType(): string
    {
        return NotificationType::REFERRAL_QUALIFIED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/referrals';

        $message = (new MailMessage)
            ->subject('Your referral qualified')
            ->line('A referral reached the configured qualification trigger.');

        if ($this->reward && (float) $this->reward->amount > 0) {
            $message->line('A reward of ' . $this->reward->currency . ' ' . number_format((float) $this->reward->amount, 2) . ' is pending in your referral ledger.');
        }

        $message->action('View referrals', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::REFERRAL_QUALIFIED,
            'referral_id' => $this->referral->id,
            'reward_status' => $this->reward?->status,
            'title' => 'Referral qualified',
            'body' => 'A referral reached the configured qualification trigger.',
        ];
    }
}
