<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;

/** Test double: records what would have been sent. Bind with `app()->instance(Notifier::class, $fake)`. */
final class FakeNotifier implements Notifier
{
    /** @var list<array{message: NotificationMessage, recipients: list<int>, channels: list<string>|null}> */
    public array $sent = [];

    public function send(NotificationMessage $message, iterable $recipients, ?array $channels = null): void
    {
        $ids = [];
        foreach ($recipients as $user) {
            $ids[] = $user->id;
        }
        $this->sent[] = ['message' => $message, 'recipients' => $ids, 'channels' => $channels];
    }

    /** @return list<array{message: NotificationMessage, recipients: list<int>, channels: list<string>|null}> */
    public function sentFor(NotificationEvent $event): array
    {
        return array_values(array_filter($this->sent, fn (array $entry): bool => $entry['message']->event === $event));
    }

    /** @return list<int> */
    public function recipientsOf(NotificationEvent $event): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (array $entry): array => $entry['recipients'], $this->sentFor($event)))));
    }

    public function hasSentTo(User $user, NotificationEvent $event): bool
    {
        return in_array($user->id, $this->recipientsOf($event), true);
    }
}
