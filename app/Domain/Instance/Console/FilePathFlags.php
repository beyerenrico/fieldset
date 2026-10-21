<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

/**
 * Which `fieldset` flags take a host file path (KTD2, ADR 0070). The shim and install.sh apply the same rules: they
 * make the value absolute and mount its parent directory at the same path, read-only for inputs and read-write
 * for outputs. Keep `distribution/shim.sh` (fieldset_file_flag_mode) in step; a test compares both.
 *
 * - outputs: `--secrets-file`, `--output`, any `--…-out`
 * - inputs: any other `--…-file`, `--from-backup`
 */
final class FilePathFlags
{
    public const string INPUT = 'in';

    public const string OUTPUT = 'out';

    /** @return self::INPUT|self::OUTPUT|null for an option name without dashes */
    public static function classify(string $option): ?string
    {
        return match (true) {
            $option === 'secrets-file', $option === 'output', str_ends_with($option, '-out') => self::OUTPUT,
            str_ends_with($option, '-file'), $option === 'from-backup' => self::INPUT,
            default => null,
        };
    }
}
