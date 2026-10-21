<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use RuntimeException;
use Throwable;

/**
 * A backup run that did not produce a backup (ADR 0084), with a stable code for the run ledger, `backup_runs` and the
 * fieldset envelope: `low_disk_space`, `snapshot_failed`, `pg_dump_failed`, `storage_failed`, `verify_failed`,
 * `encrypt_failed`, `write_failed`. Nothing of the run stays in `backups/`.
 */
final class BackupFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
