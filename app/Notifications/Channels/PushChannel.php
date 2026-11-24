<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Notifications\PushDeliveryService;
use Illuminate\Notifications\Notification;

class PushChannel
{
    public function __construct(protected PushDeliveryService $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $payload = method_exists($notification, 'toPush')
            ? $notification->toPush($notifiable)
            : $notification->toArray($notifiable);

        if (! is_array($payload) || $payload === []) {
            return;
        }

        $this->push->send($notifiable, $payload);
    }
}
