<?php

namespace App\Notifications;

use App\Models\MemberReport;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class ReportOutcomeNotification extends MemberNotification
{
    public function __construct(public MemberReport $report) {}

    protected function notificationType(): string
    {
        return NotificationType::REPORT_OUTCOME;
    }

    protected function outcome(): string
    {
        return match ($this->report->status) {
            MemberReport::STATUS_ACTIONED => 'The report was reviewed and action was taken.',
            MemberReport::STATUS_DISMISSED => 'The report was reviewed and closed without action.',
            default => 'The report was reviewed.',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/settings';

        return $this->manageLine(
            (new MailMessage)
                ->subject('Your Roicard report was reviewed')
                ->line($this->outcome())
                ->line('Moderation details remain private.')
                ->action('Open settings', $url),
            $notifiable,
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::REPORT_OUTCOME,
            'report_id' => $this->report->id,
            'status' => $this->report->status,
            'title' => 'Report reviewed',
            'body' => $this->outcome(),
        ];
    }
}
