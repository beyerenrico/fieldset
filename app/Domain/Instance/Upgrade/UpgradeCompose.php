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

    /**
     * A compose file whose storage volume is an existing (external) volume of this name, the way the renderer declares a
     * restored volume (AppRoles::storageVolume): the previous release's snapshot after a rollback restored the storage.
     */
    public static function withStorageVolume(string $raw, string $volume): string
    {
        $header = implode("\n", array_filter(explode("\n", $raw), static fn (string $line): bool => str_starts_with($line, '#')));
        $document = self::document($raw);
        $document['volumes'] = [...(is_array($document['volumes'] ?? null) ? $document['volumes'] : []), 'app-storage' => ['name' => $volume, 'external' => true]];

        return ($header === '' ? '' : $header."\n").json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return list<string> the services of a compose file */
    public static function services(string $raw): array
    {
        $services = self::document($raw)['services'] ?? [];

        return is_array($services) ? array_map(strval(...), array_keys($services)) : [];
    }
}
