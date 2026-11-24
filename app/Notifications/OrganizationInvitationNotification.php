<?php

namespace App\Notifications;

use App\Models\Community;
use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public OrganizationInvitation $invitation,
        public Community $organization,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/organizations/' . $this->organization->slug
            . '?invitation=' . $this->invitation->token;

        return (new MailMessage)
            ->subject('Invitation to join ' . $this->organization->name)
            ->line("You have been invited to join {$this->organization->name} on ROICARD.")
            ->line('Your affiliation is only shown after you accept and the organization confirms it.')
            ->action('Review invitation', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'organization_invitation',
            'invitation_id' => $this->invitation->id,
            'organization_id' => $this->organization->id,
            'organization_slug' => $this->organization->slug,
            'organization_name' => $this->organization->name,
            'token' => $this->invitation->token,
            'title' => 'Organization invitation',
            'body' => "{$this->organization->name} invited you to join their organization.",
        ];
    }
}
