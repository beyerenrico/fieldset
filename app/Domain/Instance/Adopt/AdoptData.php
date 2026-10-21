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
use App\Domain\Instance\Restore\AppKeys;
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
        private LegacyFence $fence,
    ) {}

    /** @return array{stopped: list<string>, drain: array<string, mixed>, fence: array<string, mixed>} the writers' fence (review A1) */
    public function quiesce(InstanceState $state, LegacyInstall $legacy, bool $dropJobs, int $redisDb): array
    {
        $quiesced = $this->legacy->quiesce($legacy, $state->digests()['valkey'], $state->digests()['app'], $state->uuid(), $dropJobs, $redisDb);

        return [...$quiesced, 'fence' => $this->fence->record($legacy, $state->digests()['postgres'], $state->uuid(), data: false)];
    }

    /**
     * The 1.0-format backup; the keys' fingerprints come from env/app.env, which the `secrets` phase wrote (review V5).
     *
     * @return array{name: string, path: string, bytes: int, sums: string, snapshot: array<string, mixed>, warnings: list<string>}
     */
    public function backup(InstanceState $state, LegacyInstall $legacy, LegacyCheckout $checkout, string $journal): array
    {
        $fingerprints = AppKeys::of($this->stack->secrets()->appKeys())->fingerprints();

        return $this->backups->write($legacy, ['app' => $state->digests()['app'], 'postgres' => $state->digests()['postgres']], $state->uuid(), $checkout->allowed(), $fingerprints, $journal);
    }

    /**
     * Stops 1.0's Postgres once the writers' fence still holds (review V6), then records Postgres's fence.
     *
     * @param  array<string, mixed>|null  $previous  the writers' fence (stop_1_0)
     * @return array{stopped: list<string>, fence: array<string, mixed>} Postgres's fence, with the source checkpoint
     */
    public function stopData(InstanceState $state, LegacyInstall $legacy, ?array $previous): array
    {
        $this->guard($state, $legacy, $previous);
        $stopped = $this->legacy->stopData($legacy);

        return [...$stopped, 'fence' => $this->fence->record($legacy, $state->digests()['postgres'], $state->uuid(), data: true)];
    }

    /**
     * 1.0 is still as adopt left it (LegacyFence); nothing to check before the writers stopped.
     *
     * @param  array<string, mixed>|null  $fence  the newest journaled fence
     */
    public function guard(InstanceState $state, LegacyInstall $legacy, ?array $fence): void
    {
        if ($fence !== null) {
            $this->fence->assert($legacy, $fence, $state->digests()['postgres'], $state->uuid());
        }
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
            throw new InstanceException('Unique text indexes of the copied database fail amcheck: '.implode('; ', array_slice($amcheck['failed'], 0, 3)).'.', ExitCode::HealthCheckFailed, 'amcheck_failed', 'The copy is not used and 1.0 runs again. Move the data with a 1.0 backup instead (`install.sh --dir <new directory> --from-backup <backup> --accept-unauthenticated`).', false, ['amcheck' => $amcheck]);
        }

        return ['snapshot' => $copy->toArray(), 'amcheck' => $amcheck];
    }

    /**
     * The 1.0 services that run now (started next to the finished instance); none when 1.0 cannot be read.
     *
     * @return list<string>
     */
    public function legacyRunning(LegacyInstall $legacy): array
    {
        try {
            $found = LegacyContainers::find($this->docker, $legacy->project, $legacy->checkout);
        } catch (InstanceException) {
            return [];
        }

        return array_values(array_map(strval(...), array_keys(array_filter($found, static fn (ContainerInfo $c): bool => $c->running()))));
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

        return $postgres instanceof ContainerInfo ? $postgres->id : throw new InstanceException('The instance\'s Postgres does not run.', ExitCode::HealthCheckFailed, 'services_unhealthy', 'Read `fieldset logs postgres`.', true);
    }
}
