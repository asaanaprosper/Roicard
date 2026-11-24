<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Base for Appendix C member notifications. Delivery is queued, and the
 * channel list is filtered through per-type preferences and the daily cap.
 */
abstract class MemberNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RespectsNotificationPreferences;

    protected function manageLine(MailMessage $message, object $notifiable): MailMessage
    {
        if ($notifiable instanceof User) {
            $url = $this->preferencesUrl($notifiable);

            $message->line('Manage notification preferences: ' . $url);
            $message->withSymfonyMessage(function (Email $email) use ($url) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $url . '>');
            });
        }

        return $message;
    }
}
