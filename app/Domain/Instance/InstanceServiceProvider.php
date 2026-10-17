<?php

declare(strict_types=1);

namespace App\Domain\Instance;

use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\OpsContextRequired;
use App\Domain\Instance\State\InstanceDirectory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Instance (fsa) wiring (ADR 0066). Registration only: it never opens database or cache connections, because the
 * ops container has neither (KTD1, KTD25).
 *
 * The Docker gateway resolves only after an `fsa:*` command has opened a marked instance directory
 * (FsaCommand binds the InstanceDirectory); anywhere else, web and queue included, resolving it throws.
 * The `fsa:*` commands themselves are registered here as they arrive (U5 onwards).
 */
final class InstanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HostFacts::class, static fn (): HostFacts => HostFacts::fromEnvironment(getenv()));
        $this->app->bind(DockerGateway::class, static function (Application $app): DockerGateway {
            if (! $app->bound(InstanceDirectory::class)) {
                throw new OpsContextRequired;
            }

            return $app->make(CliDockerGateway::class);
        });
    }
}
