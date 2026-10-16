<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Jobs;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\Delivery\NotificationDelivery;
use App\Domain\Notifications\NotificationMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * Delivers a notification to a batch of users (§8.9). Dispatched after the caller's transaction
 * commits (LEARNINGS #28), so recipients never see a notification about rolled-back work. Runs as
 * the message's organization, set explicitly rather than taken from the ambient context. Failures of
 * single recipients are absorbed by NotificationDelivery; a retry only follows a failure before any
 * delivery (e.g. the database was unreachable).
 */
#[Backoff([10, 60])]
#[Tries(3)]
final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly NotificationMessage $message,
        public readonly array $userIds,
        public readonly array $channels,
        public readonly ?int $organizationId,
    ) {
        $this->afterCommit();
    }

    public function handle(NotificationDelivery $delivery, TenantContext $tenant): void
    {
        $deliver = function () use ($delivery): void {
            $delivery->deliver($this->message, $this->userIds, $this->channels, $this->organizationId);
        };

        $this->organizationId === null ? $deliver() : $tenant->run($this->organizationId, $deliver);
    }
}
