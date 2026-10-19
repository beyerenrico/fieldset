<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use Closure;

/**
 * The space check before a restore changes anything (plan U17 step 1, ADR 0085; exit 3): the safety backup (the size of
 * the newest backup, or of this one), a second database (three times the dump: tables and indexes unpacked), a second
 * storage volume (the archive's unpacked bytes) and 1 GiB of headroom must fit on the file system of the instance
 * directory. Docker keeps volumes and the database in its own data root, which is the same disk on most single-disk
 * hosts; where it is not, this is the estimate fsa can make without reaching into Docker's directories.
 */
final readonly class RestoreSpace
{
    public const int HEADROOM = 1024 ** 3;

    /** Container key of a closure returning free bytes (tests). */
    public const string FREE_BYTES = 'fsa.restore.free-bytes';

    public function __construct(private BackupStore $store) {}

    /**
     * @return array{required: int, free: int|null}
     *
     * @throws InstanceException exit 3 `low_disk_space`
     */
    public function assert(PreparedBackup $backup): array
    {
        $dump = (int) @filesize($backup->set.'/database.dump');
        $entries = $this->store->entries();
        $required = ($entries === [] ? BackupStore::bytes($backup->set) : $entries[0]->bytes) + 3 * $dump + ($backup->storage['bytes'] ?? 0) + self::HEADROOM;
        $probe = app()->bound(self::FREE_BYTES) ? app(self::FREE_BYTES) : null;
        $free = $probe instanceof Closure ? $probe() : $this->store->freeBytes();
        if ($free !== null && $free < $required) {
            throw new InstanceException(
                sprintf('Not enough free disk space for the restore: %d MiB needed (safety backup, a second database and storage volume, headroom), %d MiB free.', intdiv($required, 1024 ** 2), intdiv($free, 1024 ** 2)),
                ExitCode::PreflightFailed,
                'low_disk_space',
                'Free space on the disk that holds the instance and Docker (old backups in backups/), then run the command again; nothing was changed.',
                false,
                ['required_bytes' => $required, 'free_bytes' => $free],
            );
        }

        return ['required' => $required, 'free' => $free];
    }
}
