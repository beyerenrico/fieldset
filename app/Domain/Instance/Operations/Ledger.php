<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;

/**
 * `backups/ledger.log` (R43, KTD26, plan U17; ADR 0085): the host-side, append-only record of what a reset, restore or
 * rollback replaced. Before the database changes, the operation appends one JSON line with the action, who (host UID),
 * when, and the final audit chain heads with their epoch (`audit:verify --heads`); the new database's epoch is seeded
 * from the highest epoch the ledger knows, so a recreated database never reuses an epoch number. Unlike the backup run
 * ledger, a line that cannot be written stops the operation: it is the only record of what was there.
 */
final readonly class Ledger
{
    public const string FILE = InstanceDirectory::BACKUPS_DIR.'/ledger.log';

    public function __construct(private InstanceDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $entry  action, host_uid, backup, heads, epoch, …
     *
     * @throws InstanceException exit 9 when the line cannot be written (nothing changed yet)
     */
    public function append(array $entry): void
    {
        $this->directory->ensureDirectory(InstanceDirectory::BACKUPS_DIR);
        $path = $this->directory->path(self::FILE);
        $line = json_encode(['at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), ...$entry], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
        $handle = is_link($path) ? false : @fopen($path, 'a');
        $written = $handle !== false && flock($handle, LOCK_EX) && fwrite($handle, $line) === strlen($line) && fflush($handle) && fsync($handle);
        if ($handle !== false) {
            flock($handle, LOCK_UN);
            fclose($handle);
            @chmod($path, 0600);
        }
        if (! $written) {
            throw new InstanceException('backups/ledger.log cannot be written.', ExitCode::AbortedUnchanged, 'ledger_failed', 'Check the permissions and free space of backups/, then run the command again; nothing was changed.', true);
        }
    }

    /** @return list<array<string, mixed>> every entry, oldest first (a damaged line is skipped) */
    public function entries(): array
    {
        $path = $this->directory->path(self::FILE);
        $lines = is_file($path) && ! is_link($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

        return array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), $lines), is_array(...)));
    }

    /** The highest audit chain epoch any entry recorded (0 without one): the seed of the next epoch. */
    public function seed(): int
    {
        $epochs = array_map(static fn (array $entry): int => max(is_int($entry['epoch'] ?? null) ? $entry['epoch'] : 0, is_int($entry['new_epoch'] ?? null) ? $entry['new_epoch'] : 0), $this->entries());

        return $epochs === [] ? 0 : max($epochs);
    }

    /** @return array<string, mixed>|null the newest entry of an action (restore, reset, rollback) */
    public function last(string $action): ?array
    {
        $matching = array_values(array_filter($this->entries(), static fn (array $entry): bool => ($entry['action'] ?? null) === $action));

        return $matching === [] ? null : $matching[array_key_last($matching)];
    }
}
