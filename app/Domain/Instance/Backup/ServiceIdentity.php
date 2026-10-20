<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\Operations\LockRecord;

/**
 * The backup service's lock holder (KTD4, ADR 0084). It has no Docker socket, so it cannot ask Docker for its start time
 * as the ops container does (ContainerIdentity). It records its container ID (from the mount table, the hostname as
 * fallback) and, instead of Docker's start time, the start of the container's init process: `proc:<boot ID>:<clock
 * ticks since boot>` (the kernel's random boot ID and field 22 of /proc/1/stat), which changes with every container
 * start, across host reboots too (the ticks alone could repeat after one): the daemon can tell its own lock from before
 * a restart (InstanceLock::releaseOwnStale), and fsa treats such a lock as stale only once Docker reports the container
 * gone or stopped (InstanceLock::isStale). Without a readable boot ID it is `proc:<ticks>`.
 */
final readonly class ServiceIdentity
{
    public const string OWNER = 'backup-daemon';

    public const string START_PREFIX = LockRecord::PROCESS_START_PREFIX;

    public function __construct(
        private string $mountInfo = '/proc/self/mountinfo',
        private string $initStat = '/proc/1/stat',
        private ?string $hostname = null,
        private string $bootId = '/proc/sys/kernel/random/boot_id',
    ) {}

    public function holder(): LockHolder
    {
        $hostname = $this->hostname ?? (gethostname() ?: 'unknown');
        $table = is_readable($this->mountInfo) ? (string) @file_get_contents($this->mountInfo) : '';
        $id = preg_match('#/containers/([0-9a-f]{64})/#', $table, $match) === 1 ? $match[1] : null;
        $id ??= preg_match('/^[0-9a-f]{12,64}$/D', $hostname) === 1 ? $hostname : null;

        return new LockHolder(self::OWNER, $id, $id === null ? null : $this->initStart(), (int) getmypid(), $hostname, null);
    }

    /** `proc:<boot ID>:<starttime>`: field 22 of /proc/1/stat, after the parenthesized command name. */
    private function initStart(): ?string
    {
        $stat = is_readable($this->initStat) ? (string) @file_get_contents($this->initStat) : '';
        $fields = explode(' ', trim(substr($stat, (int) strrpos($stat, ')') + 2)));
        if (! isset($fields[19]) || ! ctype_digit($fields[19])) {
            return null;
        }
        $boot = is_readable($this->bootId) ? strtolower(trim((string) @file_get_contents($this->bootId))) : '';

        return self::START_PREFIX.(preg_match('/^[0-9a-f-]{36}$/D', $boot) === 1 ? $boot.':' : '').$fields[19];
    }
}
