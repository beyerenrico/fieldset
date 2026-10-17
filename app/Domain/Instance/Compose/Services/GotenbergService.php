<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;

/**
 * The renderer has no network access (BUILD_PROMPT §8.5): Chromium may not fetch private or public addresses,
 * and the container sits only on the internal `renderer` network shared with app and queue. Concurrency and the
 * queue bound come from the budget (E16): Chromium processes are what runs a small host out of memory.
 */
final readonly class GotenbergService implements ServiceDefinition
{
    public const string NAME = 'gotenberg';

    public const string NETWORK = 'renderer';

    public function specs(RenderContext $context): array
    {
        return [new ServiceSpec(self::NAME, [
            'image' => $context->image(ImageRole::Gotenberg),
            'command' => [
                'gotenberg',
                '--chromium-deny-private-ips=true',
                '--chromium-deny-public-ips=true',
                // Documents are app-owned HTML without scripts; page numbers are Chromium placeholders.
                '--chromium-disable-javascript=true',
                '--api-body-limit=30MB',
                '--api-timeout=60s',
                '--log-format=json',
            ],
            'networks' => [self::NETWORK],
        ], new ServiceEnvironments($context)->gotenberg(), [], [self::NETWORK => ['internal' => true]])];
    }
}
