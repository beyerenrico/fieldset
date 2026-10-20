<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Mails a platform operator about a backup problem (R39, plan U20, ADR 0089): what is wrong, the `fsa` command that acts
 * on it, and a link to the `/platform` instance page, which keeps showing the warning while mail is captured. Rendered
 * in the operator's locale (the notifier sets it).
 */
final class BackupAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly ?string $reason, public readonly int $missingFiles, public readonly ?string $command) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->error()
            ->subject((string) __('instance.backup_alert.subject'))
            ->greeting((string) __('instance.backup_alert.greeting'))
            ->line((string) __('instance.problems.'.$this->code, ['reason' => $this->reason ?? 'unknown', 'count' => $this->missingFiles]));
        if ($this->command !== null) {
            $message->line((string) __('instance.backup_alert.command', ['command' => $this->command]));
        }
        $url = $this->pageUrl();

        return ($url === null ? $message : $message->action((string) __('instance.backup_alert.action'), $url))
            ->line((string) __('instance.backup_alert.persistent'));
    }

    private function pageUrl(): ?string
    {
        try {
            return route('filament.platform.pages.instance');
        } catch (Throwable) {
            return null;
        }
    }
}
