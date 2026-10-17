<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;

/**
 * The harness build of install.sh (ADR 0070): the release harness serves a copy whose single
 * `build=production` line reads `build=harness`, which accepts FSA_TRUST_KEY and plain-http release URLs. The
 * checked-in distribution/install.sh is the production build, and the release workflow publishes it unchanged.
 */
final class InstallScriptBuild
{
    public const string PRODUCTION_LINE = "\tbuild=production ";

    public static function harness(string $script): string
    {
        if (substr_count($script, self::PRODUCTION_LINE) !== 1) {
            throw new RuntimeException('install.sh must contain exactly one `build=production` line.');
        }

        return str_replace(self::PRODUCTION_LINE, "\tbuild=harness ", $script);
    }

    /** Writes the harness build of the repository's install.sh to a file and returns its path. */
    public static function writeHarness(string $target): string
    {
        file_put_contents($target, self::harness((string) file_get_contents(TestImage::repository().'/distribution/install.sh')));
        chmod($target, 0755);

        return $target;
    }
}
