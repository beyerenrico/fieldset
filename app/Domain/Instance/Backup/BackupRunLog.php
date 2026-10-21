<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Platform\Instance\BackupAlerts;
use Carbon\CarbonImmutable;

/**
 * The backup run ledger, `backups/runs.log` (KTD15, ADR 0084): one JSON line per run, appended by the backup service and
 * its one-offs, never rewritten. It holds what the `backup_runs` table holds and also the runs the database could not
 * take (a failure while Postgres is down). The daemon computes its next run from it, `fieldset status` reports from it
 * without a database, and BackupAlerts reads it like the table. Only the newest lines are read.
 */
final readonly class BackupRunLog
{
    public const string FILE = InstanceDirectory::BACKUPS_DIR.'/runs.log';

    /** The newest part of the file that is read: about a year of runs (one line each, the result details left out). */
    private const int TAIL_BYTES = 1_048_576;

    public function __construct(private InstanceDirectory $directory, private ?BackupStore $owner = null) {}

    /** @param  array<string, mixed>  $run  kind, status, started_at, finished_at, backup, bytes, encrypted, reason, message, … */
    public function append(array $run): void
    {
        $this->directory->ensureDirectory(InstanceDirectory::BACKUPS_DIR);
        $path = $this->directory->path(self::FILE);
        $line = json_encode($run, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
        $handle = is_link($path) ? false : @fopen($path, 'a');
        if ($handle === false) {
            return; // the run's result still reaches the table and the caller; a ledger line is not worth failing over
        }

        try {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $line);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
        $this->owner?->own($path, recursive: false);
    }

    /** @return list<array<string, mixed>> the newest runs first */
    public function recent(int $limit = 50): array
    {
        $path = $this->directory->path(self::FILE);
        if (! is_file($path) || is_link($path)) {
            return [];
        }
        $size = (int) filesize($path);
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);
        $runs = [];
        foreach (array_reverse(explode("\n", trim($tail))) as $line) {
            $run = json_decode($line, true);
            if (is_array($run) && is_string($run['status'] ?? null) && BackupAlerts::time($run) instanceof CarbonImmutable) {
                $runs[] = $run; // a damaged line (cut off, a time that does not parse) is skipped, never fatal
            }
            if (count($runs) >= $limit) {
                break;
            }
        }

        return $runs;
    }

    /** The newest successful run of a kind. */
    public function lastSuccess(string $kind = BackupName::SCHEDULED): ?CarbonImmutable
    {
        return BackupAlerts::time(array_find($this->recent(PHP_INT_MAX), static fn (array $run): bool => $run['status'] === 'succeeded' && ($run['kind'] ?? null) === $kind));
    }

    /**
     * The newest run of a kind that did not succeed, if it came after the last success of that kind.
     *
     * @return array{status: string, at: CarbonImmutable, reason: string|null}|null
     */
    public function lastFailedAttempt(string $kind = BackupName::SCHEDULED): ?array
    {
        $run = array_find($this->recent(PHP_INT_MAX), static fn (array $run): bool => ($run['kind'] ?? null) === $kind);
        $at = BackupAlerts::time($run);
        if ($run === null || $run['status'] === 'succeeded' || ! $at instanceof CarbonImmutable) {
            return null;
        }

        return ['status' => (string) $run['status'], 'at' => $at, 'reason' => is_string($run['reason'] ?? null) ? $run['reason'] : null];
    }
}
