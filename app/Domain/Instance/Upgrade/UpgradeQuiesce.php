<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;

/**
 * KTD19 steps 3 and 4 and their way back (plan U18, R62, R69, ADR 0086).
 *
 * `quiesce()`: the gate goes up first, so from now on the app answers every external request with 503 (in every mode:
 * only its loopback healthcheck and the operation's smoke token pass, OperationGate) and keeps answering, behind the
 * gate, until the target replaces it in step 7: the app stays up because it is the gate's responder (the plan's open
 * question on R62). Scheduler and backup stop, Horizon drains within a bounded wait and the queue stops with its grace
 * period; on a drain timeout the upgrade stops (exit 9) unless `--drop-jobs`, which saves the remaining payloads to a
 * 0600 file and counts them. Pending audit entries are sealed, so the safety backup's heads are final.
 *
 * `revert()`: a failure before the safety backup completed: the gate goes down and queue, scheduler and backup start
 * again; the app never stopped.
 */
final readonly class UpgradeQuiesce
{
    /** Stopped first: they write without a request (the app stays up behind the gate). */
    public const array FIRST = [AppRoles::SCHEDULER, BackupService::NAME];

    /** Started again by a revert and by the opening (KTD19 step 10). */
    public const array WORKERS = [AppRoles::QUEUE, AppRoles::SCHEDULER, BackupService::NAME];

    public const int WAIT_SECONDS = 300;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private QueueDrain $drain,
    ) {}

    /**
     * @return array{gate: string, drain: array<string, mixed>, dropped_jobs: array{count: int, file: string}|null}
     *
     * @throws InstanceException exit 9 `drain_timeout` (the caller reverts)
     */
    public function quiesce(InstanceState $state, ComposeProject $project, UpgradeRequest $request, string $id): array
    {
        new RuntimeWriter($this->directory)->raiseGate($state, UpgradeJournal::OPERATION);
        $running = $this->running($project);
        $first = array_values(array_intersect(self::FIRST, $running));
        if ($first !== []) {
            $this->docker->composeStop($project, $first);
        }
        $drain = $this->drain->drain($project, in_array(AppRoles::QUEUE, $running, true));
        $dropped = null;
        if (! $drain['drained']) {
            if (! $request->dropJobs) {
                throw new InstanceException(
                    "The queue did not drain within {$drain['waited']} s ({$drain['pending']} pending, {$drain['reserved']} running).", ExitCode::AbortedUnchanged, 'drain_timeout',
                    'Run `fsa upgrade` again later, or with --drop-jobs to save the remaining jobs to a file and drop them. The previous version runs again.', true, ['queue' => $drain],
                );
            }
            $dropped = $this->dropJobs($project, $id);
        }
        if (in_array(AppRoles::QUEUE, $running, true)) {
            $this->docker->composeStop($project, [AppRoles::QUEUE], AppRoles::STOP_GRACE_SECONDS);
        }
        $this->seal($project);

        return ['gate' => 'raised', 'drain' => $drain, 'dropped_jobs' => $dropped];
    }

    /** Lowers the gate and starts queue, scheduler and backup again (a failure before the safety backup, R69). */
    public function revert(InstanceState $state, ComposeProject $project): void
    {
        new RuntimeWriter($this->directory)->lowerGate($state);
        try {
            $this->docker->composeUp($project, self::WORKERS, self::WAIT_SECONDS);
        } catch (DockerCommandFailed $e) {
            throw new InstanceException('The upgrade stopped before its safety backup, but queue, scheduler or backup did not start again.', ExitCode::HealthCheckFailed, 'services_unhealthy', 'Read `fsa logs queue`; the app runs the previous version.', true, ['docker' => $e->outcome->stderrTail(5)], $e);
        }
    }

    /** @return array{count: int, file: string} */
    private function dropJobs(ComposeProject $project, string $id): array
    {
        $dropped = $this->drain->drop($project);
        $file = InstanceDirectory::OPERATIONS_DIR.'/dropped-jobs-'.$id.'.json';
        $this->directory->ensureDirectory(InstanceDirectory::OPERATIONS_DIR);
        $this->directory->write($file, json_encode(['schema_version' => '1.0', 'count' => $dropped['count'], 'payloads' => $dropped['payloads']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n", 0600);

        return ['count' => $dropped['count'], 'file' => $file];
    }

    private function seal(ComposeProject $project): void
    {
        $outcome = $this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'audit:seal'], timeout: 600.0);
        if (! $outcome->successful()) {
            throw new InstanceException('Sealing the pending audit entries failed.', ExitCode::AbortedUnchanged, 'audit_seal_failed', 'Read `fsa logs postgres`, then run `fsa upgrade` again; the previous version runs again.', true, ['output' => $outcome->stderrTail(5)]);
        }
    }

    /** @return list<string|null> services with a running container */
    private function running(ComposeProject $project): array
    {
        $containers = array_filter(ComposeFile::serviceContainers($this->docker->composePs($project)), static fn (ContainerInfo $container): bool => $container->running());

        return array_values(array_map(static fn (ContainerInfo $container): ?string => $container->service(), $containers));
    }
}
