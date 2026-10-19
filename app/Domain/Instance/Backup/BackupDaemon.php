<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHolder;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The backup service's loop (E13, KTD15, ADR 0084). On start it releases a lock its own container took before a restart.
 * Then it computes the next run from the ledger (BackupSchedule: a missed slot runs at once, a skip retries after a
 * minute, a failure after an hour), sleeps in short steps so SIGTERM stops it promptly, and runs a scheduled backup
 * under the instance lock. Only the first skip of a series is recorded; the retries after it are not.
 */
final class BackupDaemon
{
    /** Longest single sleep (seconds): the loop re-reads the clock and the stop flag at least this often. */
    public const int TICK_SECONDS = 15;

    /** When a skip retry ran last without being recorded. */
    private ?CarbonImmutable $unrecordedAttempt = null;

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
        $attempt = $this->log->lastFailedAttempt();
        if ($this->unrecordedAttempt instanceof CarbonImmutable && ($attempt === null || $this->unrecordedAttempt->greaterThan($attempt['at']))) {
            $attempt = ['status' => 'skipped', 'at' => $this->unrecordedAttempt, 'reason' => 'lock_held'];
        }
        $next = $this->schedule->nextRun($now, $this->log->lastSuccess(), $since, $attempt);
        if (! $next instanceof CarbonImmutable || $next->greaterThan($now)) {
            ($this->sleep)($next instanceof CarbonImmutable ? max(1, (int) min(self::TICK_SECONDS, ceil($now->diffInSeconds($next, true)))) : self::TICK_SECONDS);

            return null;
        }
        $first = ($attempt['status'] ?? null) !== 'skipped';
        $run = $this->run->run(BackupName::for(BackupName::SCHEDULED, $now), $this->holder, recordSkip: $first);
        $this->unrecordedAttempt = $run['status'] === 'skipped' && ! $first ? $now : null;

        return $run;
    }
}
