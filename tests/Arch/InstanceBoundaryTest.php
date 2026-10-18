<?php

declare(strict_types=1);

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\InstanceServiceProvider;
use Illuminate\Support\Facades\Process;

/*
 * Instance boundaries (plan KTD1, KTD24, KTD25; ADR 0066).
 *
 * - App\Domain\Instance\Runtime (URL resolution, operation gate, tunnel path policy; U10) is the only instance
 *   code that web and queue processes load, so it must not depend on the ops side.
 * - No code outside the instance domain may reach the ops namespaces, the Docker gateway above all: web, queue
 *   and scheduler code never holds the socket. Web code (middleware, health checks) reaches the instance domain only
 *   through Runtime, never the Docker gateway or the State writers (U10, ADR 0074).
 * - Every subdirectory and top-level class of app/Domain/Instance except Runtime counts as ops, also when it is
 *   added later and not yet listed below.
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
    'App\Domain\Instance\Install',
    'App\Domain\Instance\Lifecycle',
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

$app = dirname(__DIR__, 2).'/app';
$instanceOps = array_values(array_unique([
    ...INSTANCE_OPS_NAMESPACES,
    ...array_map(
        static fn (string $path): string => 'App\\Domain\\Instance\\'.basename($path, '.php'),
        array_filter(glob($app.'/Domain/Instance/*') ?: [], static fn (string $path): bool => basename($path) !== 'Runtime'),
    ),
]));

arch('the instance runtime has no ops dependencies')
    ->expect('App\Domain\Instance\Runtime')
    ->not->toUse([...$instanceOps, 'Symfony\Component\Process', 'Illuminate\Process', Process::class]);

arch('the instance runtime does not depend on the HTTP or panel layers')
    ->expect('App\Domain\Instance\Runtime')
    ->not->toUse(['App\Http', 'App\Filament', 'App\Providers']);

$outside = [
    ...array_map(static fn (string $dir): string => 'App\\'.basename($dir), array_filter(glob($app.'/*', GLOB_ONLYDIR) ?: [], static fn (string $dir): bool => basename($dir) !== 'Domain')),
    ...array_map(static fn (string $dir): string => 'App\\Domain\\'.basename($dir), array_filter(glob($app.'/Domain/*', GLOB_ONLYDIR) ?: [], static fn (string $dir): bool => basename($dir) !== 'Instance')),
];

foreach ($outside as $namespace) {
    arch("{$namespace} never reaches the instance ops side or the Docker gateway")
        ->expect($namespace)
        ->not->toUse($instanceOps);
}
