<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

/**
 * An instance's `fieldset` script, the shim (ADR 0049, ADR 0070), with the version it declares (2.1.1 U15, KTD7): the
 * `fieldset_shim_version=<n>` line of distribution/shim.sh, which the shim passes to the ops container as
 * FIELDSET_LAUNCHER_VERSION. Scripts before 2.1.1 declare none (version 0). The image's own script is the one install.sh
 * extracts from it (`/var/www/html/distribution/shim.sh`).
 */
final readonly class LauncherScript
{
    /** Its name in the instance directory, where install.sh writes it. */
    public const string FILE = 'fieldset';

    private const string VERSION_PATTERN = '/^fieldset_shim_version=([1-9][0-9]{0,8})$/m';

    /** A larger file is no shim: it reads as version 0. */
    private const int MAX_BYTES = 1_048_576;

    private function __construct(
        public string $contents,
        public int $version,
    ) {}

    /** This image's script, or null where it is missing or declares no version (never in a built image). */
    public static function bundled(?string $path = null): ?self
    {
        $script = self::read($path ?? base_path('distribution/shim.sh'));

        return $script instanceof self && $script->version > 0 ? $script : null;
    }

    /** The script at a path when it is a regular file (never through a symlink), else null. */
    public static function read(string $path): ?self
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($contents === false) {
            return null;
        }

        return new self($contents, strlen($contents) > self::MAX_BYTES ? 0 : self::versionOf($contents));
    }

    /** The version a script declares; 0 for one that declares none (before 2.1.1). */
    public static function versionOf(string $contents): int
    {
        return preg_match(self::VERSION_PATTERN, $contents, $match) === 1 ? (int) $match[1] : 0;
    }
}
