<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceState;

/**
 * Copies the stopped 1.0 volumes into the instance's own (KTD20 step 4, R60, plan U19, ADR 0087). Compose creates the
 * instance's `pg-data` and `app-storage` volumes, labelled like every other, by a no-op one-off of the service that mounts
 * each; then a one-off of the manifest's Postgres image (the same Alpine/musl family as 1.0's `postgres:16-alpine`, so text
 * indexes stay valid) copies each 1.0 volume, mounted read-only, with `cp -a` (owners, modes, times, links), without a
 * network. 1.0's volumes are never written. The copied cluster must say it was shut down cleanly (`pg_controldata`), so
 * 2.0's Postgres starts without crash recovery. A resumed copy removes the half-filled volumes of the earlier attempt first.
 */
final readonly class VolumeCopy
{
    public const float TIMEOUT = 21600.0;

    /** Where both images keep the cluster. */
    public const string DATA_PATH = '/var/lib/postgresql/data';

    public function __construct(private DockerGateway $docker) {}

    /** @return array<string, string> `pg-data` / `app-storage` => the instance's volume */
    public static function targets(InstanceState $state): array
    {
        return [LegacyInstall::DATA_VOLUME => $state->project().'_'.PostgresService::VOLUME, LegacyInstall::STORAGE_VOLUME => $state->project().'_'.AppRoles::STORAGE_VOLUME];
    }

    /**
     * @return array{volumes: array<string, string>, cluster_state: string}
     *
     * @throws InstanceException exit 4 `copy_failed`, `postgres_not_shut_down`
     */
    public function copy(InstanceState $state, ComposeProject $project, LegacyInstall $legacy): array
    {
        $targets = array_intersect_key(self::targets($state), $legacy->volumes);
        $this->removeEarlier($state, $targets);
        foreach ([PostgresService::NAME => LegacyInstall::DATA_VOLUME, ComposeRenderer::ONE_OFF_SERVICE => LegacyInstall::STORAGE_VOLUME] as $service => $key) {
            if (isset($targets[$key])) {
                $this->assertRan($this->docker->composeRun($project, $service, ['true'], timeout: 600.0), "creating the volume {$targets[$key]}");
            }
        }
        foreach ($targets as $key => $target) {
            $this->assertRan($this->docker->runContainer(new ContainerSpec(
                image: $state->digests()['postgres'], command: ['-a', '/from/.', '/to/'], labels: Labels::forInstance($state->uuid(), DatabaseProbe::ROLE),
                volumes: [$legacy->volumes[$key].':/from:ro', $target.':/to'], network: 'none', user: '0:0', entrypoint: 'cp',
            )->hardened(ContainerSpec::OWNER_COPY), null, self::TIMEOUT), "copying {$legacy->volumes[$key]}");
        }

        return ['volumes' => $targets, 'cluster_state' => $this->clusterState($state, $targets[LegacyInstall::DATA_VOLUME])];
    }

    /** @param  array<string, string>  $targets */
    private function removeEarlier(InstanceState $state, array $targets): void
    {
        foreach ($this->docker->volumes([Labels::INSTANCE => $state->uuid()]) as $volume) {
            if ($volume->kind === DockerResource::VOLUME && in_array($volume->name, $targets, true)) {
                $this->docker->removeVolume($volume->name);
            }
        }
    }

    private function clusterState(InstanceState $state, string $volume): string
    {
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['postgres'], command: [self::DATA_PATH], labels: Labels::forInstance($state->uuid(), DatabaseProbe::ROLE),
            volumes: [$volume.':'.self::DATA_PATH.':ro'], network: 'none', user: '0:0', entrypoint: 'pg_controldata',
        )->hardened(ContainerSpec::READ_ANY), null, 120.0);
        $cluster = preg_match('/^Database cluster state:\s*(.+)$/m', $outcome->stdout, $match) === 1 ? trim($match[1]) : null;
        if (! $outcome->successful() || $cluster !== 'shut down') {
            throw new InstanceException('The copied 1.0 database was not shut down cleanly ('.($cluster ?? $outcome->stderrTail(2)).').', ExitCode::HealthCheckFailed, 'postgres_not_shut_down', 'Run adopt again: 1.0 is started again and stopped once more.', true);
        }

        return $cluster;
    }

    private function assertRan(ProcessOutcome $outcome, string $what): void
    {
        if (! $outcome->successful()) {
            throw new InstanceException("Adopt failed {$what}: ".$outcome->stderrTail(3), ExitCode::HealthCheckFailed, 'copy_failed', 'Check the free space of Docker\'s data root; 1.0\'s volumes were only read.', true);
        }
    }
}
