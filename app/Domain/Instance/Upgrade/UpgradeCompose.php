<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

/**
 * Reads a generated compose file's JSON behind its comment header (ADR 0069), as a string: the staged target's and the
 * previous release's snapshot in the state (plan U18, ADR 0086). The file holds no secret.
 */
final class UpgradeCompose
{
    /** @return array<string, mixed> */
    public static function document(string $raw): array
    {
        $json = implode("\n", array_filter(explode("\n", $raw), static fn (string $line): bool => ! str_starts_with($line, '#')));
        $document = json_decode($json, true);

        return is_array($document) ? $document : [];
    }

    /** @return list<string> the services of a compose file */
    public static function services(string $raw): array
    {
        $services = self::document($raw)['services'] ?? [];

        return is_array($services) ? array_map(strval(...), array_keys($services)) : [];
    }
}
