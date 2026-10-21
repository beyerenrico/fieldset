<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/**
 * Production or release-harness build (R58, ADR 0070). The image records it at build time from the `FIELDSET_BUILD`
 * build argument in `distribution/.build-flavor`; only the release harness passes `FIELDSET_BUILD=harness`. A harness
 * build accepts a local test key (FIELDSET_TRUST_KEY); anything but an explicit "harness" counts as production, so a
 * missing or damaged file never opens the key path. It is read from a file, not the environment, so a
 * `docker run -e` cannot turn a production image into a harness one.
 */
enum BuildFlavor: string
{
    case Production = 'production';
    case Harness = 'harness';

    public const string FILE = 'distribution/.build-flavor';

    public static function current(?string $file = null): self
    {
        $file ??= base_path(self::FILE);
        $contents = is_file($file) && ! is_link($file) ? file_get_contents($file) : false;

        return is_string($contents) && trim($contents) === self::Harness->value ? self::Harness : self::Production;
    }

    public function acceptsTrustKey(): bool
    {
        return $this === self::Harness;
    }
}
