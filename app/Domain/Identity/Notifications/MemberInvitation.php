<?php

declare(strict_types=1);

namespace App\Domain\Identity\Notifications;

use App\Domain\Identity\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class MemberInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Membership $membership) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->membership->organization;
        $url = URL::temporarySignedRoute('invitations.show', now()->addDays(7), ['membership' => $this->membership->id]);

        return (new MailMessage)
            ->subject(__('identity.invitation.subject', ['organization' => $organization->name]))
            ->greeting(__('identity.invitation.greeting'))
            ->line(__('identity.invitation.body', ['organization' => $organization->name]))
            ->action(__('identity.invitation.action'), $url)
            ->line(__('identity.invitation.expiry'));
    }
}
