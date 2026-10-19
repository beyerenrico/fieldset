<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use RuntimeException;
use SensitiveParameter;

/**
 * `SHA256SUMS` and its HMAC (R67, KTD16, ADR 0084). The sums use sha256sum's format (`<hex>  <name>`, sorted by name),
 * so `sha256sum -c SHA256SUMS` checks a backup by hand. `SHA256SUMS.hmac` holds the hex HMAC-SHA256 of the sums file's
 * bytes, keyed with BACKUP_HMAC_KEY: HKDF-SHA256 of the raw APP_KEY with the info `form-schema-app backup hmac v1`
 * (ServiceEnvironments), so a restore authenticates a backup with keys from outside the archive. Both files are written
 * last, under temporary names, and renamed at the end: a backup with these two files is complete.
 */
final readonly class BackupChecksums
{
    public const string SUMS = 'SHA256SUMS';

    public const string HMAC = 'SHA256SUMS.hmac';

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
        sort($files);
        $lines = '';
        foreach ($files as $file) {
            $hash = hash_file('sha256', $directory.'/'.$file);
            if ($hash === false) {
                throw new RuntimeException("Cannot read {$file} for its checksum.");
            }
            $lines .= $hash.'  '.$file."\n";
        }

        return $lines;
    }

    public static function hmac(string $sums, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $sums, $key);
    }

    /**
     * Writes both files under temporary names and renames them last.
     *
     * @param  list<string>  $files
     * @return string the sums file's content
     */
    public static function write(string $directory, array $files, #[SensitiveParameter] string $key): string
    {
        $sums = self::sums($directory, $files);
        $temporary = [self::SUMS => $directory.'/.'.self::SUMS.'.tmp', self::HMAC => $directory.'/.'.self::HMAC.'.tmp'];
        foreach ([self::SUMS => $sums, self::HMAC => self::hmac($sums, $key)."\n"] as $name => $content) {
            if (file_put_contents($temporary[$name], $content) !== strlen($content) || ! chmod($temporary[$name], 0600)) {
                throw new RuntimeException("Cannot write {$name}.");
            }
        }
        foreach ($temporary as $name => $path) {
            if (! rename($path, $directory.'/'.$name)) {
                throw new RuntimeException("Cannot rename {$name} into place.");
            }
        }

        return $sums;
    }

    /**
     * Problems with a plain backup set: a file whose checksum does not match, a listed file that is missing, a file
     * that is not listed, or an HMAC that does not match the key. Empty when the set verifies.
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
        $listed = [];
        foreach (array_filter(explode("\n", $sums)) as $line) {
            [$hash, $file] = array_pad(explode('  ', $line, 2), 2, '');
            $listed[] = $file;
            $actual = is_file($directory.'/'.$file) ? hash_file('sha256', $directory.'/'.$file) : false;
            if ($actual === false || ! hash_equals($hash, $actual)) {
                $problems[] = $actual === false ? "{$file} is missing" : "{$file} does not match its checksum";
            }
        }
        $present = array_diff(scandir($directory) ?: [], ['.', '..', self::SUMS, self::HMAC, BackupStore::KEEP_MARKER]);
        foreach (array_diff($present, $listed) as $extra) {
            $problems[] = "{$extra} is not listed in SHA256SUMS";
        }

        return $problems;
    }
}
