<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\SafeFiles;
use RuntimeException;
use SensitiveParameter;

/**
 * `SHA256SUMS` and its HMAC (R67, KTD16, ADR 0084). The sums use sha256sum's format (`<hex>  <name>`, sorted by name),
 * so `sha256sum -c SHA256SUMS` checks a backup by hand. `SHA256SUMS.hmac` holds the hex HMAC-SHA256 of the sums file's
 * bytes, keyed with BACKUP_HMAC_KEY: HKDF-SHA256 of the raw APP_KEY with the info `fieldset backup hmac v1`
 * (ServiceEnvironments), so a restore authenticates a backup with keys from outside the archive. Both files are written
 * last, under temporary names, and renamed at the end: a backup with these two files is complete.
 */
final readonly class BackupChecksums
{
    public const string SUMS = 'SHA256SUMS';

    public const string HMAC = 'SHA256SUMS.hmac';

    /** A listed file name: plain, no path, no leading dot (ADR 0085). */
    private const string NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    /** The raw key from BACKUP_HMAC_KEY (`base64:` + 32 bytes); an unusable key fails the run, never a weak HMAC. */
    public static function key(#[SensitiveParameter] string $value): string
    {
        $raw = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;
        if (! is_string($raw) || strlen($raw) < 32) {
            throw new InstanceException('BACKUP_HMAC_KEY is missing or not a base64 key of 32 bytes.', ExitCode::InvalidInput, 'invalid_backup_config');
        }

        return $raw;
    }

    /** @param  list<string>  $files  names inside `$directory` */
    public static function sums(string $directory, array $files): string
    {
        $hashes = [];
        foreach ($files as $file) {
            $hash = hash_file('sha256', $directory.'/'.$file);
            if ($hash === false) {
                throw new RuntimeException("Cannot read {$file} for its checksum.");
            }
            $hashes[$file] = $hash;
        }

        return self::lines($hashes);
    }

    /** @param  array<string, string>  $hashes  name => SHA-256, in sha256sum's format sorted by name */
    public static function lines(array $hashes): string
    {
        ksort($hashes, SORT_STRING);
        $lines = '';
        foreach ($hashes as $file => $hash) {
            $lines .= $hash.'  '.$file."\n";
        }

        return $lines;
    }

    public static function hmac(string $sums, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $sums, $key);
    }

    /**
     * Writes both files under temporary names, synced, and renames them last (then the directory is synced).
     *
     * @param  list<string>  $files
     * @return string the sums file's content
     */
    public static function write(string $directory, array $files, #[SensitiveParameter] string $key): string
    {
        $sums = self::sums($directory, $files);
        $temporary = [self::SUMS => $directory.'/.'.self::SUMS.'.tmp', self::HMAC => $directory.'/.'.self::HMAC.'.tmp'];
        foreach ([self::SUMS => $sums, self::HMAC => self::hmac($sums, $key)."\n"] as $name => $content) {
            @unlink($temporary[$name]);
            ProducedFile::written($temporary[$name], $content);
        }
        foreach ($temporary as $name => $path) {
            if (! rename($path, $directory.'/'.$name)) {
                throw new RuntimeException("Cannot rename {$name} into place.");
            }
        }
        SafeFiles::syncDirectory($directory);

        return $sums;
    }

    /**
     * The sums of a set being written, from the hashes its writer recorded (never read back by path, M12 gate S1), and
     * their HMAC: both become files of the set.
     *
     * @return string the sums file's content
     */
    public static function seal(PartialSet $set, #[SensitiveParameter] string $key): string
    {
        $sums = self::lines($set->hashes());
        $set->write(self::SUMS, $sums);
        $set->write(self::HMAC, self::hmac($sums, $key)."\n");

        return $sums;
    }

    /**
     * Problems with a plain backup set: an HMAC that does not match the key, then everything verifySums() finds. Empty
     * when the set verifies.
     *
     * @return list<string>
     */
    public static function verify(string $directory, #[SensitiveParameter] string $key): array
    {
        $sums = @file_get_contents($directory.'/'.self::SUMS);
        $hmac = @file_get_contents($directory.'/'.self::HMAC);
        if (! is_string($sums) || ! is_string($hmac)) {
            return ['SHA256SUMS or its HMAC is missing'];
        }
        $problems = hash_equals(self::hmac($sums, $key), trim($hmac)) ? [] : ['the HMAC does not match (another APP_KEY, or the sums were changed)'];

        return [...$problems, ...self::verifySums($directory)];
    }

    /**
     * The checksums alone (also for the 1.0 format, which has no HMAC; plan U17, ADR 0085): a listed name that is not
     * a plain file name (a `/`, `..`, a control character: a restore reads foreign archives) is refused before anything
     * is hashed; then a listed file that is missing or does not match, and a file that is not listed. Stray dotfiles a
     * user or a killed seal leaves (`.DS_Store`, `.backup.tar.age.tmp`) are not part of the set: strays() reports them.
     *
     * @return list<string>
     */
    public static function verifySums(string $directory): array
    {
        $sums = @file_get_contents($directory.'/'.self::SUMS);
        if (! is_string($sums)) {
            return ['SHA256SUMS is missing'];
        }
        $entries = self::entries($sums);
        if (is_string($entries)) {
            return [$entries];
        }
        $problems = [];
        foreach ($entries as $file => $hash) {
            $path = $directory.'/'.$file;
            $actual = is_file($path) && ! is_link($path) ? hash_file('sha256', $path) : false;
            if ($actual === false || ! hash_equals($hash, $actual)) {
                $problems[] = $actual === false ? "{$file} is missing" : "{$file} does not match its checksum";
            }
        }
        foreach (array_diff(self::present($directory), array_keys($entries)) as $extra) {
            $problems[] = "{$extra} is not listed in SHA256SUMS";
        }

        return $problems;
    }

    /** @return list<string> dotfiles in the set's directory, ignored by the verification (ADR 0085) */
    public static function strays(string $directory): array
    {
        $strays = array_values(array_filter(scandir($directory) ?: [], static fn (string $entry): bool => str_starts_with($entry, '.') && ! in_array($entry, ['.', '..'], true)));
        sort($strays);

        return $strays;
    }

    /**
     * The listed names and hashes, or the problem that makes the file unusable.
     *
     * @return array<string, string>|string
     */
    private static function entries(string $sums): array|string
    {
        $entries = [];
        foreach (array_filter(explode("\n", $sums), static fn (string $line): bool => $line !== '') as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](.*)$/sD', $line, $match) !== 1) {
                return 'SHA256SUMS has a line that is not in sha256sum format';
            }
            if (preg_match(self::NAME, $match[2]) !== 1) {
                return 'SHA256SUMS lists an unsafe name: '.json_encode($match[2], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            $entries[$match[2]] = $match[1];
        }

        return $entries;
    }

    /** @return list<string> the set's files: everything but the sums, the HMAC, the keep marker and dotfiles */
    private static function present(string $directory): array
    {
        return array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $entry): bool => ! str_starts_with($entry, '.') && ! in_array($entry, [self::SUMS, self::HMAC, BackupStore::KEEP_MARKER], true),
        ));
    }
}
