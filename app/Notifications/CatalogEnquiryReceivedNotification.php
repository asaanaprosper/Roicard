<?php

namespace App\Notifications;

use App\Models\CatalogEnquiry;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CatalogEnquiryReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    public function __construct(public CatalogEnquiry $enquiry) {}

    protected function notificationType(): string
    {
        return NotificationType::CATALOG_ENQUIRY;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/dashboard/profile/catalog';

        return (new MailMessage)
            ->subject('New catalog enquiry' . ($this->enquiry->item ? ': ' . $this->enquiry->item->title : ''))
            ->line("{$this->enquiry->name} sent an enquiry from your business catalog.")
            ->line('Message: ' . $this->enquiry->message)
            ->line("Reply to: {$this->enquiry->email}")
            ->action('View catalog', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'catalog_enquiry',
            'enquiry_id' => $this->enquiry->id,
            'catalog_item_id' => $this->enquiry->catalog_item_id,
            'name' => $this->enquiry->name,
            'email' => $this->enquiry->email,
            'message' => $this->enquiry->message,
            'title' => 'New catalog enquiry',
            'body' => "{$this->enquiry->name} is interested in your catalog item.",
        ];
    }
}
