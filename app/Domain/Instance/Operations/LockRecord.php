<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

/**
 * The owner record inside the lock directory. `complete` is false for a lock directory whose record was never
 * written (its creator died between mkdir and write).
 */
final readonly class LockRecord
{
    public const string SCHEMA_VERSION = '1.0';

    public function __construct(
        public string $lockId,
        public string $owner,
        public string $operation,
        public ?string $containerId,
        public ?string $containerStartedAt,
        public ?int $pid,
        public ?string $hostname,
        public ?int $hostUid,
        public ?string $acquiredAt,
        public ?string $heartbeatAt = null,
        public bool $complete = true,
    ) {}

    public static function forHolder(LockHolder $holder, string $operation, string $now): self
    {
        return new self(
            bin2hex(random_bytes(16)), $holder->owner, $operation, $holder->containerId, $holder->containerStartedAt,
            $holder->pid, $holder->hostname, $holder->hostUid, $now,
        );
    }

    public static function incomplete(?string $since): self
    {
        return new self('', 'unknown', 'unknown', null, null, null, null, null, $since, null, false);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data, ?string $heartbeatAt = null): self
    {
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $int = static fn (string $key): ?int => is_int($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            $string('lock_id') ?? '', $string('owner') ?? 'unknown', $string('operation') ?? 'unknown',
            $string('container_id'), $string('container_started_at'), $int('pid'), $string('hostname'),
            $int('host_uid'), $string('acquired_at'), $heartbeatAt,
        );
    }

    /** @return array<string, string|int|bool|null> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'lock_id' => $this->lockId,
            'owner' => $this->owner,
            'operation' => $this->operation,
            'container_id' => $this->containerId,
            'container_started_at' => $this->containerStartedAt,
            'pid' => $this->pid,
            'hostname' => $this->hostname,
            'host_uid' => $this->hostUid,
            'acquired_at' => $this->acquiredAt,
            'heartbeat_at' => $this->heartbeatAt,
            'complete' => $this->complete,
        ];
    }

    /** "upgrade by fsa (container 0123456789ab, since 2026-10-17T10:00:00Z)" */
    public function describe(): string
    {
        if (! $this->complete) {
            return 'an operation that is still starting (lock directory without owner record)';
        }
        $where = $this->containerId === null
            ? 'pid '.($this->pid ?? '?').' on '.($this->hostname ?? '?')
            : 'container '.substr($this->containerId, 0, 12);

        return "{$this->operation} by {$this->owner} ({$where}, since ".($this->acquiredAt ?? '?').')';
    }
}
