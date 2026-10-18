<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;

/**
 * Valkey as the `redis` service, as today, now with a password (§10) and its data on a labelled volume. Its
 * `maxmemory` comes from the budget; `volatile-lru` evicts only keys with a TTL (cache entries), never the queue
 * lists, which have none: when nothing is evictable, writes fail instead of jobs being dropped.
 */
final readonly class ValkeyService implements ServiceDefinition
{
    public const string NAME = 'redis';

    private const string VOLUME = 'valkey-data';

    public function specs(RenderContext $context): array
    {
        return [new ServiceSpec(self::NAME, [
            'image' => $context->image(ImageRole::Valkey),
            'command' => ['valkey-server', '--maxmemory', $context->budget->valkeyMaxMemory(), '--maxmemory-policy', 'volatile-lru'],
            'volumes' => [['type' => 'volume', 'source' => self::VOLUME, 'target' => '/data']],
            'networks' => ['default'],
            'healthcheck' => ['test' => ['CMD-SHELL', 'valkey-cli ping | grep -q PONG'], 'interval' => '5s', 'timeout' => '3s', 'retries' => 10],
        ], new ServiceEnvironments($context)->valkey(), [self::VOLUME => []])];
    }
}
