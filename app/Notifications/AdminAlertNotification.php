<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class AdminAlertNotification extends MemberNotification
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $alertType,
        public string $title,
        public string $body,
        public array $context = [],
        public ?string $actionUrl = null,
    ) {}

    protected function notificationType(): string
    {
        return $this->alertType;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Roicard admin alert: ' . $this->title)
            ->line($this->body);

        if ($this->actionUrl) {
            $message->action('Open admin console', $this->actionUrl);
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->alertType,
            'title' => $this->title,
            'body' => $this->body,
            'context' => $this->context,
            'action_url' => $this->actionUrl,
        ];
    }
}
