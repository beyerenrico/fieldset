<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * Reads the generated compose file (JSON behind a comment header, ADR 0069) for the services an instance runs,
 * and tells service containers from one-offs. The file holds no secret; env files are never read here.
 */
final class ComposeFile
{
    public const string ONE_OFF_LABEL = 'com.docker.compose.oneoff';

    /** @return list<string> */
    public static function services(InstanceDirectory $directory): array
    {
        $services = self::document($directory)['services'] ?? [];

        return is_array($services) ? array_map(strval(...), array_keys($services)) : [];
    }

    /** @return array<string, mixed> */
    public static function document(InstanceDirectory $directory): array
    {
        $raw = $directory->read(InstanceDirectory::COMPOSE_FILE);
        if ($raw === null) {
            return [];
        }
        $json = implode("\n", array_filter(explode("\n", $raw), static fn (string $line): bool => ! str_starts_with($line, '#')));
        $document = json_decode($json, true);

        return is_array($document) ? $document : [];
    }

    /**
     * Service containers only (no `compose run` one-offs).
     *
     * @param  list<ContainerInfo>  $containers
     * @return list<ContainerInfo>
     */
    public static function serviceContainers(array $containers): array
    {
        return array_values(array_filter($containers, static fn (ContainerInfo $container): bool => strtolower($container->labels[self::ONE_OFF_LABEL] ?? '') !== 'true'));
    }
}
