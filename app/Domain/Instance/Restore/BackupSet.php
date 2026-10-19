<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\BackupManifest;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\BackupWriter;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * What a restore can read (plan U17, R60, ADR 0084/0085): an fsa backup, plain (a directory with `manifest.json`) or
 * encrypted (a directory with `backup.tar.age`, or that file alone), and the 1.0 format of `scripts/backup.sh` (a
 * directory with `manifest.txt`, `SHA256SUMS`, `database.dump`, optionally `storage.tar.gz` and `env`; no HMAC).
 */
final readonly class BackupSet
{
    public const string FSA = 'fsa';

    public const string LEGACY = 'legacy';

    /** The 1.0 format's own files. */
    public const string LEGACY_MANIFEST = 'manifest.txt';

    public const string LEGACY_ENV = 'env';

    /** Every name the set of an fsa backup can hold (the members of its age file). */
    public const array FILES = [
        BackupChecksums::SUMS, BackupChecksums::HMAC, BackupManifest::FILE, BackupWriter::SETTINGS, BackupWriter::HEADS,
        BackupWriter::DATABASE, BackupWriter::STORAGE, BackupWriter::SECRETS,
    ];

    /**
     * @return array{format: string, encrypted: bool, file: string|null} the format, and the age file of an encrypted one
     *
     * @throws InstanceException exit 2 when the path is no backup this version reads
     */
    public static function detect(string $path): array
    {
        if (is_file($path) && ! is_link($path) && str_ends_with($path, '.age')) {
            return ['format' => self::FSA, 'encrypted' => true, 'file' => $path];
        }
        if (! is_dir($path) || is_link($path)) {
            throw self::notABackup($path);
        }
        $age = $path.'/'.BackupStore::ENCRYPTED_FILE;

        return match (true) {
            is_file($age) && ! is_link($age) => ['format' => self::FSA, 'encrypted' => true, 'file' => $age],
            is_file($path.'/'.BackupManifest::FILE) => ['format' => self::FSA, 'encrypted' => false, 'file' => null],
            is_file($path.'/'.self::LEGACY_MANIFEST) && is_file($path.'/'.BackupChecksums::SUMS) => ['format' => self::LEGACY, 'encrypted' => false, 'file' => null],
            default => throw self::notABackup($path),
        };
    }

    /**
     * The 1.0 `manifest.txt` (`key=value` lines): created_at, migrations, last_migration, …
     *
     * @return array<string, string>
     */
    public static function legacyManifest(string $set): array
    {
        $values = [];
        foreach (explode("\n", (string) @file_get_contents($set.'/'.self::LEGACY_MANIFEST)) as $line) {
            if (preg_match('/^([a-z_]{1,40})=(.{0,500})$/D', rtrim($line, "\r"), $match) === 1) {
                $values[$match[1]] = $match[2];
            }
        }

        return $values;
    }

    /**
     * Refuses a plain set whose entries are not all regular files (a symlink planted in a backup directory would make a
     * restore read a file outside it).
     *
     * @throws UnsafeArchive
     */
    public static function assertRegularFiles(string $set): void
    {
        foreach (scandir($set) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $path = $set.'/'.$entry;
            if (is_link($path) || ! is_file($path)) {
                throw new UnsafeArchive('The backup directory holds '.(is_link($path) ? 'a symlink' : 'something other than a file').': '.json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).'.');
            }
        }
    }

    private static function notABackup(string $path): InstanceException
    {
        return new InstanceException(
            'This is not a backup fsa can restore: '.$path,
            ExitCode::InvalidInput,
            'not_a_backup',
            'Pass a backup directory from backups/ (or its backup.tar.age), or a directory written by the 1.0 scripts/backup.sh.',
        );
    }
}
