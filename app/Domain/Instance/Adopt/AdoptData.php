<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Install\InstanceStack;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceState;

/**
 * The data path of an adopt, KTD20 steps 1–5 (plan U19, ADR 0087): quiesce 1.0 (LegacyStack), take the 1.0-format backup
 * (LegacyBackupWriter), stop 1.0's Postgres, copy the volumes (VolumeCopy), then verify the copy before anything of 2.0
 * runs on it: the instance's Postgres starts alone on the copied cluster, the 1.0 role gets the instance's password, and
 * the copy's counts, audit chain heads and migration set must equal the backup's exactly, and its unique text indexes
 * must pass amcheck. Any failure here leaves 1.0's volumes as they were; the caller rolls back.
 */
final readonly class AdoptData
{
    public function __construct(
        private DockerGateway $docker,
        private LegacyStack $legacy,
        private LegacyBackupWriter $backups,
        private VolumeCopy $copy,
        private DatabaseProbe $probe,
        private InstanceStack $stack,
    ) {}

    /** @return array{stopped: list<string>, drain: array<string, mixed>} */
    public function quiesce(InstanceState $state, LegacyInstall $legacy, bool $dropJobs): array
    {
        return $this->legacy->quiesce($legacy, $state->digests()['valkey'], $state->digests()['app'], $state->uuid(), $dropJobs);
    }

    /** @return array{name: string, path: string, bytes: int, snapshot: array<string, mixed>, warnings: list<string>} */
    public function backup(InstanceState $state, LegacyInstall $legacy, LegacyCheckout $checkout): array
    {
        return $this->backups->write($legacy, ['app' => $state->digests()['app'], 'postgres' => $state->digests()['postgres']], $state->uuid(), $checkout->allowed());
    }

    /** @return array{stopped: list<string>} */
    public function stopData(LegacyInstall $legacy): array
    {
        return $this->legacy->stopData($legacy);
    }

    /** @return array{volumes: array<string, string>, cluster_state: string} */
    public function copy(InstanceState $state, ComposeProject $project, LegacyInstall $legacy): array
    {
        return $this->copy->copy($state, $project, $legacy);
    }

    /**
     * @param  DataSnapshot  $expected  the backup's
     * @return array{snapshot: array<string, mixed>, amcheck: array{checked: list<string>, failed: list<string>}}
     *
     * @throws InstanceException exit 4 `copy_mismatch`, `amcheck_failed`
     */
    public function verify(InstanceState $state, ComposeProject $project, LegacyInstall $legacy, DataSnapshot $expected): array
    {
        $this->stack->up($project, [PostgresService::NAME]);
        $postgres = $this->postgres($project);
        $image = $state->digests()['postgres'];
        $this->probe->setPassword($image, $postgres, $legacy->database, $legacy->username, $this->stack->secrets()->dbPassword, $state->uuid());
        $copy = $this->probe->snapshot($image, $postgres, $legacy->database, $legacy->username, $state->uuid());
        $differences = $copy->differences($expected);
        if ($differences !== []) {
            throw new InstanceException('The copied database differs from the 1.0 backup: '.implode('; ', array_slice($differences, 0, 5)).'.', ExitCode::HealthCheckFailed, 'copy_mismatch', 'Run adopt again; 1.0 runs again unchanged. Report it if it repeats.', true, ['differences' => $differences]);
        }
        $amcheck = $this->probe->amcheck($image, $postgres, $legacy->database, $legacy->username, $state->uuid());
        if ($amcheck['failed'] !== []) {
            throw new InstanceException('Unique text indexes of the copied database fail amcheck: '.implode('; ', array_slice($amcheck['failed'], 0, 3)).'.', ExitCode::HealthCheckFailed, 'amcheck_failed', 'The copy is not used and 1.0 runs again. Move the data with a 1.0 backup instead (`fsa install --from-backup`).', false, ['amcheck' => $amcheck]);
        }

        return ['snapshot' => $copy->toArray(), 'amcheck' => $amcheck];
    }

    /** What the instance holds now: the baseline of uninstall's "writes since adopt". */
    public function snapshot(InstanceState $state, ComposeProject $project, LegacyInstall $legacy): DataSnapshot
    {
        return $this->probe->snapshot($state->digests()['postgres'], $this->postgres($project), $legacy->database, $legacy->username, $state->uuid());
    }

    private function postgres(ComposeProject $project): string
    {
        $containers = ComposeFile::serviceContainers($this->docker->composePs($project));
        $postgres = array_find($containers, static fn (ContainerInfo $c): bool => $c->service() === PostgresService::NAME && $c->running());

        return $postgres instanceof ContainerInfo ? $postgres->id : throw new InstanceException('The instance\'s Postgres does not run.', ExitCode::HealthCheckFailed, 'services_unhealthy', 'Read `fsa logs postgres`.', true);
    }
}
