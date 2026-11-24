<?php

namespace App\Notifications;

use App\Models\Profile;
use App\Support\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

class WeeklyRecommendationsNotification extends MemberNotification
{
    /**
     * @param  array<int, array<string, mixed>>  $recommendations
     */
    public function __construct(
        public Profile $profile,
        public array $recommendations,
    ) {}

    protected function notificationType(): string
    {
        return NotificationType::WEEKLY_RECOMMENDATIONS;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/dashboard/discover?profile_id=' . $this->profile->id;

        $message = (new MailMessage)
            ->subject('Your weekly Roicard recommendations')
            ->line('Here are members whose Seeking and Offering may fit yours this week.');

        foreach (array_slice($this->recommendations, 0, 5) as $item) {
            $profile = $item['profile'] ?? $item;
            $name = $profile['display_name'] ?? trim(($profile['user']['first_name'] ?? '') . ' ' . ($profile['user']['last_name'] ?? ''));
            $title = $profile['title'] ?? $profile['organisation'] ?? null;

            $message->line(trim($name . ($title ? ' — ' . $title : '')));
        }

        $message->action('View recommendations', $url);

        return $this->manageLine($message, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::WEEKLY_RECOMMENDATIONS,
            'profile_id' => $this->profile->id,
            'recommendations' => array_map(fn (array $item) => [
                'id' => $item['profile']['id'] ?? null,
                'slug' => $item['profile']['slug'] ?? null,
                'display_name' => $item['profile']['display_name'] ?? null,
                'title' => $item['profile']['title'] ?? null,
            ], array_slice($this->recommendations, 0, 5)),
            'title' => 'Your weekly recommendations',
            'body' => 'New member matches are ready for your profile.',
        ];
    }

    public function toPush(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
