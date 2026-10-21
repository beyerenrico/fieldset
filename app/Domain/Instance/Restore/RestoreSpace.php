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
 * hosts; where it is not, this is the estimate fieldset can make without reaching into Docker's directories. When the free
 * space cannot be read at all the check passes with `free: null` and the restore warns `disk_space_unknown` (review A12):
 * refusing would block every restore on a host whose file system does not report it.
 */
final readonly class RestoreSpace
{
    public const int HEADROOM = 1024 ** 3;

    /** Container key of a closure returning free bytes (tests). */
    public const string FREE_BYTES = 'fieldset.restore.free-bytes';

    public function __construct(private BackupStore $store) {}

    /**
     * Before the prepare step writes anything: room in the scratch directory for the decrypted tar and the set extracted
     * from it (an encrypted backup: twice its age file), or for the copy of a plain backup's set, which every later step
     * reads instead of the backup directory (its dump, storage archive and small files, M12 gate), plus the headroom.
     *
     * @return int|null the free bytes, null when they cannot be read (the caller warns `disk_space_unknown`)
     *
     * @throws InstanceException exit 3 `low_disk_space`
     */
    public function assertScratch(string $source): ?int
    {
        $age = is_file($source) ? $source : $source.'/'.BackupStore::ENCRYPTED_FILE;
        $bytes = is_file($age) ? 2 * (int) filesize($age) : BackupSet::bytes($source);

        return $this->check($bytes + self::HEADROOM, 'the decrypted or copied backup in the scratch directory');
    }

    /**
     * @return array{required: int, free: int|null}
     *
     * @throws InstanceException exit 3 `low_disk_space`
     */
    public function assert(PreparedBackup $backup, bool $safetyTaken = false): array
    {
        $dump = (int) @filesize($backup->set.'/database.dump');
        $entries = $this->store->entries();
        $safety = $safetyTaken ? 0 : ($entries === [] ? BackupStore::bytes($backup->set) : $entries[0]->bytes); // a resumed run has it already
        $required = $safety + 3 * $dump + ($backup->storage['bytes'] ?? 0) + self::HEADROOM;
        $free = $this->check($required, 'the safety backup, a second database and storage volume');

        return ['required' => $required, 'free' => $free];
    }

    private function check(int $required, string $what): ?int
    {
        $probe = app()->bound(self::FREE_BYTES) ? app(self::FREE_BYTES) : null;
        $free = $probe instanceof Closure ? $probe() : $this->store->freeBytes();
        if ($free !== null && $free < $required) {
            throw new InstanceException(
                sprintf('Not enough free disk space for the restore: %d MiB needed (%s, headroom), %d MiB free.', intdiv($required, 1024 ** 2), $what, intdiv($free, 1024 ** 2)),
                ExitCode::PreflightFailed,
                'low_disk_space',
                'Free space on the disk that holds the instance and Docker (old backups in backups/), then run the command again; nothing was changed.',
                false,
                ['required_bytes' => $required, 'free_bytes' => $free],
            );
        }

        return $free;
    }
}
