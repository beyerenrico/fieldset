<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;

/**
 * Finds the container this process runs in, for the lock's holder record (KTD4). The full ID comes from the
 * mount table (`…/containers/<id>/hostname` is bind-mounted into every container, rootful and rootless); the
 * hostname (Docker's default is the short ID) is the fallback. Docker then reports the start time.
 */
final readonly class ContainerIdentity
{
    public function __construct(
        private DockerGateway $docker,
        private string $mountInfo = '/proc/self/mountinfo',
        private ?string $hostname = null,
    ) {}

    /** The current container as Docker reports it; null when this process does not run in a container. */
    public function current(): ?ContainerInfo
    {
        $id = $this->idFromMountTable() ?? $this->idFromHostname();

        return $id === null ? null : $this->docker->inspectContainer($id);
    }

    public function holder(string $owner, ?int $hostUid): LockHolder
    {
        $self = $this->current();

        return new LockHolder($owner, $self?->id, $self?->startedAt, (int) getmypid(), $this->hostname(), $hostUid);
    }

    private function idFromMountTable(): ?string
    {
        $table = is_file($this->mountInfo) ? (string) @file_get_contents($this->mountInfo) : '';

        return preg_match('#/containers/([0-9a-f]{64})/#', $table, $match) === 1 ? $match[1] : null;
    }

    private function idFromHostname(): ?string
    {
        return preg_match('/^[0-9a-f]{12,64}$/', $this->hostname()) === 1 ? $this->hostname() : null;
    }

    private function hostname(): string
    {
        return $this->hostname ?? (gethostname() ?: 'unknown');
    }
}
