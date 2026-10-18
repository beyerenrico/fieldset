<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Queued test notification (plan U10): a mail whose button links into the Field App. */
final class RuntimeLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()->subject('Runtime link')->action('Open', route('field-app', ['path' => 'tasks']));
    }
}
