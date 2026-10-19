<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use Illuminate\Support\Sleep;

/**
 * Stops and starts the 1.0 install `fsa adopt` takes over (KTD20 steps 1 and 3, plan U19, ADR 0087), container by container
 * (`docker container stop/start`), never through Compose: Compose would recreate 1.0's containers from a file fsa cannot
 * render the way 1.0 did. Order: app, scheduler and every other running service that is no infrastructure (writers()),
 * then the queue once Horizon drained it (QueueDrain, in 1.0's Valkey database) with its stop grace, then, after the
 * backup, Postgres with a long grace (a clean shutdown: the copy must start without recovery), Valkey and Gotenberg.
 * Starting again (a failed adopt) brings back the services that ran when adopt began, by the containers Docker lists for
 * them now (LegacyContainers), Postgres first and healthy before the app, which migrates on start.
 */
final readonly class LegacyStack
{
    /** Postgres finishes its shutdown checkpoint within this. */
    public const int POSTGRES_GRACE = 120;

    /** How long a restarted Postgres may take to report healthy before the app roles start anyway. */
    public const int HEALTH_WAIT = 60;

    public function __construct(private DockerGateway $docker, private QueueDrain $drain, private PortProbe $ports) {}

    /**
     * @return array{stopped: list<string>, drain: array<string, mixed>}
     *
     * @throws InstanceException exit 4 `drain_timeout` (jobs left) or `drain_unknown` (they cannot be counted), both only
     *                           without `--drop-jobs`, and `port_taken`
     */
    public function quiesce(LegacyInstall $legacy, string $valkeyImage, string $appImage, string $uuid, bool $dropJobs, int $redisDb = 0): array
    {
        $writers = $legacy->runningIds(self::writers($legacy));
        $this->docker->stopContainers($writers, AppRoles::STOP_GRACE_SECONDS);
        $queue = $legacy->runningIds([LegacyInstall::QUEUE]);
        $drain = $queue === [] ? ['drained' => true, 'waiting' => null, 'reserved' => null, 'delayed' => null, 'waited_seconds' => 0] : $this->drain->drain($legacy, $valkeyImage, $uuid, $redisDb);
        if ($drain['drained'] !== true && ! $dropJobs) {
            $unknown = $drain['drained'] === null;
            throw new InstanceException(
                $unknown ? "The jobs in 1.0's Valkey could not be counted, so fsa cannot tell whether its queue drained." : "1.0's queue did not drain within ".QueueDrain::SECONDS." s ({$drain['waiting']} waiting, {$drain['reserved']} running).",
                ExitCode::HealthCheckFailed, $unknown ? 'drain_unknown' : 'drain_timeout',
                ($unknown ? 'Check that 1.0\'s Valkey answers without a password on its default network, ' : 'Let 1.0 finish its jobs and run adopt again, ')
                    .'or pass --drop-jobs: jobs left then stay in 1.0\'s Valkey (they run if 1.0 is started again).',
                true, ['drain' => $drain],
            );
        }
        $this->docker->stopContainers($queue, AppRoles::STOP_GRACE_SECONDS);
        if ($legacy->runningIds(['app']) !== [] && ! $this->ports->isFree($legacy->port, $appImage, $uuid)) {
            throw new InstanceException("Port {$legacy->port} is still in use after 1.0's app stopped.", ExitCode::HealthCheckFailed, 'port_taken', 'Find what else listens on 127.0.0.1:'.$legacy->port.' and stop it, then run adopt again.', true);
        }

        return ['stopped' => [...$writers, ...$queue], 'drain' => $drain];
    }

    /**
     * Every service that ran and is neither infrastructure nor the queue: app and scheduler, and any service added to 1.0's
     * compose.yaml (review A1), all stopped before the queue drains.
     *
     * @return list<string>
     */
    public static function writers(LegacyInstall $legacy): array
    {
        $others = array_diff($legacy->running, [...LegacyInstall::INFRASTRUCTURE, LegacyInstall::QUEUE, ...LegacyInstall::WRITERS]);

        return [...LegacyInstall::WRITERS, ...array_values($others)];
    }

    /** @return array{stopped: list<string>} Postgres (cleanly, with its grace), then Valkey and Gotenberg */
    public function stopData(LegacyInstall $legacy): array
    {
        $postgres = $legacy->runningIds([LegacyInstall::POSTGRES]);
        $this->docker->stopContainers($postgres, self::POSTGRES_GRACE);
        $rest = $legacy->runningIds(array_values(array_diff(LegacyInstall::INFRASTRUCTURE, [LegacyInstall::POSTGRES])));
        $this->docker->stopContainers($rest, 30);

        return ['stopped' => [...$postgres, ...$rest]];
    }

    /**
     * Starts what ran when adopt began (starting a running container changes nothing).
     *
     * @return list<string> the services started
     */
    public function restart(LegacyInstall $legacy): array
    {
        $legacy = $this->current($legacy);
        $infrastructure = $legacy->runningIds(LegacyInstall::INFRASTRUCTURE);
        $this->docker->startContainers($infrastructure);
        if ($infrastructure !== []) {
            $this->awaitPostgres($legacy);
        }
        $this->docker->startContainers($legacy->runningIds([...self::writers($legacy), LegacyInstall::QUEUE]));

        return array_values(array_filter([...LegacyInstall::INFRASTRUCTURE, ...self::writers($legacy), LegacyInstall::QUEUE], static fn (string $service): bool => in_array($service, $legacy->running, true)));
    }

    /**
     * The services that ran when adopt began and do not run now (a container that exits right after its start).
     *
     * @return list<string>
     */
    public function notRunning(LegacyInstall $legacy): array
    {
        $legacy = $this->current($legacy);

        return array_values(array_filter($legacy->running, function (string $service) use ($legacy): bool {
            $container = isset($legacy->containers[$service]) ? $this->docker->inspectContainer($legacy->containers[$service]) : null;

            return ! $container instanceof ContainerInfo || ! $container->running();
        }));
    }

    /**
     * The install with its containers as Docker lists them now (one recreated since adopt began has a new ID), checked like
     * at discovery (LegacyContainers: the checkout's labels on every container, one per service).
     */
    private function current(LegacyInstall $legacy): LegacyInstall
    {
        $found = LegacyContainers::find($this->docker, $legacy->project, $legacy->checkout);

        return $legacy->withContainers([...$legacy->containers, ...array_map(static fn (ContainerInfo $c): string => $c->id, $found)]);
    }

    private function awaitPostgres(LegacyInstall $legacy): void
    {
        for ($waited = 0; $waited < self::HEALTH_WAIT; $waited += 2) {
            $container = $this->docker->inspectContainer($legacy->postgres());
            if (! $container instanceof ContainerInfo || ! $container->running() || in_array($container->health, [null, 'healthy'], true)) {
                return;
            }
            Sleep::for(2)->seconds();
        }
    }
}
