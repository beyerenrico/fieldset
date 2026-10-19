<?php

declare(strict_types=1);

namespace App\Domain\Instance;

use App\Domain\Instance\Console\BackupCommand;
use App\Domain\Instance\Console\BackupDaemonCommand;
use App\Domain\Instance\Console\ConfigCommand;
use App\Domain\Instance\Console\DemoCommand;
use App\Domain\Instance\Console\DoctorCommand;
use App\Domain\Instance\Console\InstallCommand;
use App\Domain\Instance\Console\LoginLinkCommand;
use App\Domain\Instance\Console\LogsCommand;
use App\Domain\Instance\Console\ResetCommand;
use App\Domain\Instance\Console\RestoreCommand;
use App\Domain\Instance\Console\RestorePrepareCommand;
use App\Domain\Instance\Console\StatusCommand;
use App\Domain\Instance\Console\TunnelCommand;
use App\Domain\Instance\Console\UninstallCommand;
use App\Domain\Instance\Console\VersionCommand;
use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\OpsContextRequired;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Release\BuildFlavor;
use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\TrustPolicy;
use App\Domain\Instance\State\InstanceDirectory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Instance (fsa) wiring (ADR 0066). Registration only: it never opens database or cache connections, because the
 * ops container has neither (KTD1, KTD25).
 *
 * The Docker gateway resolves only after an `fsa:*` command has opened a marked instance directory
 * (FsaCommand binds the InstanceDirectory); anywhere else, web and queue included, resolving it throws.
 * The `fsa:*` commands are registered here as they arrive (U5 onwards); constructing them touches nothing.
 */
final class InstanceServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    public const array COMMANDS = [
        InstallCommand::class, DoctorCommand::class, StatusCommand::class, LogsCommand::class,
        VersionCommand::class, UninstallCommand::class, LoginLinkCommand::class, ConfigCommand::class, DemoCommand::class,
        TunnelCommand::class, BackupCommand::class, BackupDaemonCommand::class, RestorePrepareCommand::class, RestoreCommand::class,
        ResetCommand::class,
    ];

    public function register(): void
    {
        $this->app->singleton(HostFacts::class, static fn (): HostFacts => HostFacts::fromEnvironment(getenv()));
        $this->app->singleton(Interruption::class); // one per command: a signal of the tunnel cleanup reaches the command's guard
        $this->app->bind(DockerGateway::class, static function (Application $app): DockerGateway {
            if (! $app->bound(InstanceDirectory::class)) {
                throw new OpsContextRequired;
            }

            return $app->make(CliDockerGateway::class);
        });
        // Release verification (R58, ADR 0070): the identity is built in; FSA_TRUST_KEY only in harness builds.
        $this->app->bind(TrustPolicy::class, static fn (): TrustPolicy => TrustPolicy::fromEnvironment(getenv(), BuildFlavor::current()));
        $this->app->bind(ManifestSchema::class, static fn (): ManifestSchema => ManifestSchema::bundled());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(self::COMMANDS);
        }
    }
}
