<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * The Notifier (§8.9): in-app (database, shown by the admin bell and `GET /api/v1/notifications`)
 * and branded email through the mail module. `send()` only dedupes the recipients and queues
 * DeliverNotification jobs (after commit, in batches); rendering per locale, preferences and the
 * membership checks happen in the job (NotificationDelivery). The organization is the message's,
 * else the current tenant's.
 */
final readonly class QueuedNotifier implements Notifier
{
    private const int BATCH_SIZE = 100;

    public function __construct(private TenantContext $tenant, private Dispatcher $bus) {}

    public function send(NotificationMessage $message, iterable $recipients, ?array $channels = null): void
    {
        $userIds = $this->userIds($recipients);
        $channels = $channels === null
            ? ChannelPreferences::CHANNELS
            : array_values(array_intersect(ChannelPreferences::CHANNELS, $channels));

        if ($userIds === [] || $channels === []) {
            return;
        }

        $organizationId = $message->organizationId ?? $this->tenant->id();
        foreach (array_chunk($userIds, self::BATCH_SIZE) as $batch) {
            $this->bus->dispatch(new DeliverNotification($message, $batch, $channels, $organizationId));
        }
    }

    /**
     * @param  iterable<mixed>  $recipients
     * @return list<int> unique user ids, in first-seen order
     */
    private function userIds(iterable $recipients): array
    {
        $ids = [];
        foreach ($recipients as $user) {
            if ($user instanceof User) {
                $ids[$user->id] = $user->id;
            }
        }

        return array_values($ids);
    }
}
