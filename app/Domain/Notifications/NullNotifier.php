<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/** Placeholder binding until the notification channels are wired (M4-T06). */
final class NullNotifier implements Notifier
{
    public function send(NotificationMessage $message, iterable $recipients, ?array $channels = null): void {}
}
