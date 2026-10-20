<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\BackupStore;
use Closure;
use RuntimeException;

/**
 * Unpacks foreign backup archives safely (R41, plan U17, ADR 0085). Every member is judged before anything is written:
 *
 * - the set of an encrypted backup (`backup.tar` once age decrypted it) holds only regular files with the names of a
 *   backup set (BackupSet::FILES), each once, and is extracted into a fresh scratch directory, files created
 *   exclusively (0600), never following a link;
 * - the files of a plain set (a backup directory) are copied into a fresh scratch directory the same way (copySet), so
 *   what is checked is what is restored;
 * - the storage archive (`storage.tar.gz`) may hold regular files and directories below its root; an absolute path,
 *   a `..` segment, a control character, a symlink, a hard link, a device, a FIFO or any other member type refuses the
 *   whole archive. It is not extracted here: GNU tar extracts it later as the app UID into a fresh volume, after this
 *   check passed; `members()` lists its regular files for the drill and the file-reference check.
 */
final readonly class SafeExtractor
{
    private const int CHUNK = 1 << 20;

    /**
     * Checks a set archive, then extracts it into `$target` (an empty directory).
     *
     * @param  list<string>  $allowed  the member names a set may hold
     * @return list<string> the extracted names, sorted
     *
     * @throws UnsafeArchive
     */
    public static function extractSet(string $archive, string $target, array $allowed): array
    {
        if ((scandir($target) ?: ['x']) !== ['.', '..']) {
            throw new RuntimeException('The scratch directory for the backup set is not empty.');
        }
        $names = [];
        self::read($archive, false, static function (TarMember $member) use ($allowed, &$names): void {
            if ($member->type !== TarMember::REGULAR || ! in_array($member->name, $allowed, true) || isset($names[$member->name])) {
                throw new UnsafeArchive('The backup set holds a member a backup never has: '.self::describe($member).'.');
            }
            $names[$member->name] = true;
        });
        self::read($archive, false, static function (TarMember $member, Closure $read) use ($target): void {
            self::write($target.'/'.$member->name, $member->size, $read);
        });
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Copies the files of a plain set (a backup directory) into `$target` (an empty directory) before anything checks
     * them, so every later step reads this copy and never the directory whoever else writes into `backups/` can change
     * meanwhile (M12 gate). Only names in `$allowed` are copied, each a regular file: checked with lstat, opened, and
     * the open file must be the same inode (a symlink swapped in between is refused). Dotfiles (strays) and the keep
     * marker are skipped; every other name is returned, and the caller counts it as a problem of the set.
     *
     * @param  list<string>  $allowed  the names a set of this format may hold
     * @return list<string> the other names in the directory, sorted
     *
     * @throws UnsafeArchive
     */
    public static function copySet(string $source, string $target, array $allowed): array
    {
        if ((scandir($target) ?: ['x']) !== ['.', '..']) {
            throw new RuntimeException('The scratch directory for the backup set is not empty.');
        }
        $others = [];
        foreach (scandir($source) ?: [] as $name) {
            if (str_starts_with($name, '.') || $name === BackupStore::KEEP_MARKER) {
                continue;
            }
            if (in_array($name, $allowed, true)) {
                self::copyFile($source.'/'.$name, $target.'/'.$name);
            } else {
                $others[] = $name;
            }
        }

        return $others;
    }

    /**
     * Checks a storage archive (gzip'd tar) member by member and writes the names of its regular files, one per line
     * and without the leading `./`, to `$listing` when given.
     *
     * @return array{files: int, directories: int, bytes: int}
     *
     * @throws UnsafeArchive
     */
    public static function checkStorage(string $archive, ?string $listing = null): array
    {
        $out = $listing === null ? null : fopen($listing, 'x');
        if ($out === false) {
            throw new RuntimeException('Cannot create the storage member listing.');
        }
        $counts = ['files' => 0, 'directories' => 0, 'bytes' => 0];

        try {
            self::read($archive, true, static function (TarMember $member) use (&$counts, $out): void {
                $name = self::storageName($member);
                if ($member->type === TarMember::DIRECTORY) {
                    $counts['directories']++;

                    return;
                }
                $counts['files']++;
                $counts['bytes'] += $member->size;
                if ($out !== null) {
                    fwrite($out, $name."\n");
                }
            });
        } finally {
            if ($out !== null) {
                fclose($out);
            }
        }

        return $counts;
    }

    /**
     * A storage member's path below the archive root: `./` prefixes and a directory's trailing `/` go; what is left must
     * be a plain relative path.
     *
     * @throws UnsafeArchive
     */
    public static function storageName(TarMember $member): string
    {
        if (! in_array($member->type, [TarMember::REGULAR, TarMember::DIRECTORY], true)) {
            throw new UnsafeArchive('The storage archive holds '.self::describe($member).'; only files and directories are restored.');
        }
        $name = $member->name;
        if (str_starts_with($name, '/') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new UnsafeArchive('The storage archive holds an absolute or unprintable path: '.self::describe($member).'.');
        }
        while (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }
        $name = $member->type === TarMember::DIRECTORY ? rtrim($name, '/') : $name;
        if (in_array($name, ['', '.'], true) && $member->type === TarMember::DIRECTORY) {
            return '.';
        }
        foreach (explode('/', $name) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new UnsafeArchive('The storage archive holds a path that leaves its root or is not plain: '.self::describe($member).'.');
            }
        }

        return $name;
    }

    /**
     * @param  Closure(TarMember, Closure(int): string): void  $each
     *
     * @throws UnsafeArchive
     */
    private static function read(string $archive, bool $gzip, Closure $each): void
    {
        if ($gzip && (string) @file_get_contents($archive, false, null, 0, 2) !== "\x1f\x8b") {
            throw new UnsafeArchive(basename($archive).' is not a gzip archive.');
        }
        $stream = @fopen(($gzip ? 'compress.zlib://' : '').$archive, 'rb');
        if ($stream === false) {
            throw new UnsafeArchive('Cannot open '.basename($archive).'.');
        }

        try {
            new TarStream($stream)->each($each);
        } finally {
            fclose($stream);
        }
    }

    /** @throws UnsafeArchive */
    private static function copyFile(string $from, string $to): void
    {
        $name = json_encode(basename($from), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $before = @lstat($from);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
            throw new UnsafeArchive('The backup directory holds '.(is_link($from) ? 'a symlink' : 'something other than a file').': '.$name.'.');
        }
        $handle = @fopen($from, 'rb');
        $opened = $handle === false ? false : fstat($handle);
        if ($handle === false || $opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) {
            if ($handle !== false) {
                fclose($handle);
            }

            throw new UnsafeArchive('A file of the backup directory changed while it was copied: '.$name.'.');
        }

        try {
            self::write($to, $opened['size'], static fn (int $length): string => $length < 1 ? '' : (string) fread($handle, $length));
        } finally {
            fclose($handle);
        }
    }

    /** @param  Closure(int): string  $read */
    private static function write(string $path, int $size, Closure $read): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Cannot create '.basename($path).' in the scratch directory.');
        }

        try {
            for ($left = $size; $left > 0; $left -= strlen($chunk)) {
                $chunk = $read(min(self::CHUNK, $left));
                if ($chunk === '' || fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Cannot write '.basename($path).' in the scratch directory.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private static function describe(TarMember $member): string
    {
        $kind = match ($member->type) {
            TarMember::REGULAR => 'file', TarMember::DIRECTORY => 'directory', '1' => 'hard link', '2' => 'symlink',
            '3' => 'character device', '4' => 'block device', '6' => 'FIFO', default => "member of type '{$member->type}'",
        };

        return $kind.' '.json_encode($member->name, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
