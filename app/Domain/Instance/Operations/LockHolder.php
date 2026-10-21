<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

/**
 * Who asks for the instance lock: the fieldset process, its container and when that container started (KTD4).
 * Outside a container (development) container ID and start time are null, and such a lock is never stale.
 */
final readonly class LockHolder
{
    public function __construct(
        public string $owner,
        public ?string $containerId,
        public ?string $containerStartedAt,
        public int $pid,
        public string $hostname,
        public ?int $hostUid,
    ) {}
}
