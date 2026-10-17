<?php

declare(strict_types=1);

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\InstanceServiceProvider;

/*
 * Instance boundaries (plan KTD1, KTD24, KTD25; ADR 0066).
 *
 * - App\Domain\Instance\Runtime (URL resolution, operation gate, tunnel path policy; U10) is the only instance
 *   code that web and queue processes load, so it must not depend on the ops side.
 * - No code outside the instance domain may reach the ops namespaces, the Docker gateway above all: web, queue
 *   and scheduler code never holds the socket.
 *
 * One rule per subject namespace on purpose: Pest 5 arch expectations on an array of several subjects, and
 * `->ignoring()` (which filters dependencies, not subjects), let violations through (LEARNINGS).
 */

const INSTANCE_OPS_NAMESPACES = [
    'App\Domain\Instance\Docker',
    'App\Domain\Instance\State',
    'App\Domain\Instance\Operations',
    'App\Domain\Instance\Output',
    'App\Domain\Instance\Console',
    'App\Domain\Instance\Compose',
    'App\Domain\Instance\Doctor',
    'App\Domain\Instance\Backup',
    'App\Domain\Instance\Restore',
    'App\Domain\Instance\Upgrade',
    'App\Domain\Instance\Adopt',
    'App\Domain\Instance\Tunnel',
    'App\Domain\Instance\Release',
    InstanceServiceProvider::class,
    HostFacts::class,
    InstanceException::class,
];

arch('the instance runtime has no ops dependencies')
    ->expect('App\Domain\Instance\Runtime')
    ->not->toUse([...INSTANCE_OPS_NAMESPACES, 'Symfony\Component\Process']);

$app = dirname(__DIR__, 2).'/app';
$outside = [
    ...array_map(static fn (string $dir): string => 'App\\'.basename($dir), array_filter(glob($app.'/*', GLOB_ONLYDIR) ?: [], static fn (string $dir): bool => basename($dir) !== 'Domain')),
    ...array_map(static fn (string $dir): string => 'App\\Domain\\'.basename($dir), array_filter(glob($app.'/Domain/*', GLOB_ONLYDIR) ?: [], static fn (string $dir): bool => basename($dir) !== 'Instance')),
];

foreach ($outside as $namespace) {
    arch("{$namespace} never reaches the instance ops side or the Docker gateway")
        ->expect($namespace)
        ->not->toUse(INSTANCE_OPS_NAMESPACES);
}
