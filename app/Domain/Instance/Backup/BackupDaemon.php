<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHolder;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The backup service's loop (E13, KTD15, ADR 0084). On start it releases a lock its own container took before a restart.
 * Then it computes the next run from the ledger (BackupSchedule: a missed slot runs at once, a skip because of the lock
 * retries after a minute, a failure or a skip for disk space after an hour), sleeps in short steps so SIGTERM stops it
 * promptly, and runs a scheduled backup under the instance lock. Of a series of skips because of the lock, one an hour
 * is recorded (a lock a crashed operation left behind keeps showing up); the retries in between are not. It also keeps
 * its own last run in memory, so a ledger it cannot write never makes it run back to back.
 */
final class BackupDaemon
{
    /** Longest single sleep (seconds): the loop re-reads the clock and the stop flag at least this often. */
    public const int TICK_SECONDS = 15;

    /** Seconds between two recorded skips of one series (the retries in between are not recorded). */
    public const int SKIP_RECORD_SECONDS = 3600;

    /** This process's newest successful scheduled run. */
    private ?CarbonImmutable $success = null;

    /**
     * This process's newest scheduled run that did not succeed, recorded or not.
     *
     * @var array{status: string, at: CarbonImmutable, reason: string|null}|null
     */
    private ?array $attempt = null;

    /**
     * @param  Closure(int): void  $sleep
     * @param  Closure(): CarbonImmutable  $now
     */
    public function __construct(
        private readonly BackupSchedule $schedule,
        private readonly BackupRunLog $log,
        private readonly BackupRun $run,
        private readonly InstanceLock $lock,
        private readonly LockHolder $holder,
        private readonly Closure $sleep,
        private readonly Closure $now,
    ) {}

    /**
     * Runs until `$stop` says so.
     *
     * @param  Closure(): bool  $stop
     * @param  (Closure(array<string, mixed>): void)|null  $ran  hears each run (the command logs it)
     */
    public function loop(Closure $stop, ?Closure $ran = null): void
    {
        $this->lock->releaseOwnStale($this->holder);
        $since = ($this->now)();
        while (! $stop()) {
            $run = $this->tick($since);
            if ($run !== null && $ran instanceof Closure) {
                $ran($run);
            }
        }
    }

    /**
     * One step: a run when one is due, else a sleep of at most TICK_SECONDS.
     *
     * @return array<string, mixed>|null the run, when one ran
     */
    public function tick(CarbonImmutable $since): ?array
    {
        $now = ($this->now)();
        $recorded = $this->log->lastFailedAttempt();
        $success = $this->newest($this->log->lastSuccess(), $this->success);
        $attempt = $this->attempt !== null && ($recorded === null || $this->attempt['at']->greaterThan($recorded['at'])) ? $this->attempt : $recorded;
        $next = $this->schedule->nextRun($now, $success, $since, $attempt);
        if (! $next instanceof CarbonImmutable || $next->greaterThan($now)) {
            ($this->sleep)($next instanceof CarbonImmutable ? max(1, (int) min(self::TICK_SECONDS, ceil($now->diffInSeconds($next, true)))) : self::TICK_SECONDS);

            return null;
        }
        $repeat = $recorded !== null && $recorded['status'] === 'skipped' && $recorded['reason'] === 'lock_held'
            && $recorded['at']->greaterThan($now->subSeconds(self::SKIP_RECORD_SECONDS));
        $run = $this->run->run(BackupName::for(BackupName::SCHEDULED, $now), $this->holder, recordSkip: ! $repeat);
        if ($run['status'] === 'succeeded') {
            $this->success = $now;
            $this->attempt = null;
        } else {
            $this->attempt = ['status' => (string) $run['status'], 'at' => $now, 'reason' => is_string($run['reason'] ?? null) ? $run['reason'] : null];
        }

        return $run;
    }

    private function newest(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a instanceof CarbonImmutable && (! $b instanceof CarbonImmutable || $a->greaterThan($b)) ? $a : $b;
    }
}
