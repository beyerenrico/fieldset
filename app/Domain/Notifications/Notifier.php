<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;

/**
 * Sends a notification to users on their enabled channels (§8.9). `$channels` narrows the channels a
 * caller allows (e.g. a workflow `notify` step); user preferences always apply on top. Delivery is
 * queued; implementations never throw for a single unreachable recipient.
 */
interface Notifier
{
    public const string CHANNEL_DATABASE = 'database';

    public const string CHANNEL_MAIL = 'mail';

    /**
     * @param  iterable<User>  $recipients
     * @param  list<string>|null  $channels  subset of CHANNEL_* or null for all
     */
    public function send(NotificationMessage $message, iterable $recipients, ?array $channels = null): void;
}
