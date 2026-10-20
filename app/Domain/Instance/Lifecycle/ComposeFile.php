<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceState;

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
     * Whether the current compose file was rendered for emulated images (DOCKER_DEFAULT_PLATFORM, ADR 0070): every memory
     * limit doubled. A render without that would recreate every service, so re-renders keep it.
     */
    public static function emulated(InstanceDirectory $directory, ComposeRenderer $renderer, InstanceState $state, InstanceSecrets $secrets): bool
    {
        $current = self::document($directory)['services']['app']['mem_limit'] ?? null;
        $native = $renderer->render($state, $secrets)->service('app')['mem_limit'] ?? null;

        return is_string($current) && is_string($native) && (int) $current === 2 * (int) $native;
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
