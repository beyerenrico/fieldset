<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * Keeps 1.0 stopped while adopt works on its data (R46 "refuses to continue while its containers run", plan U19, ADR 0087,
 * review A1). Once adopt stopped 1.0's writers, and again once it stopped 1.0's Postgres, it records a fence: every
 * container of the project with its start time (Docker sets `StartedAt` anew on every start, so a container started and
 * stopped again in between is noticed too) and, after Postgres stopped, the source cluster's `pg_controldata` state and
 * latest checkpoint (a cluster started by any other container moves it). Before the backup, before and after the copy and
 * on every run before 2.0 starts, adopt checks the journaled fence: a container that runs (before Postgres stopped: one
 * that is not Postgres, Valkey or Gotenberg), one started again, removed or added, or a moved checkpoint means 1.0 took
 * writes the copy may not have; adopt then goes back (exit 5 `legacy_restarted`) instead of continuing on stale data.
 */
final readonly class LegacyFence
{
    public function __construct(private DockerGateway $docker) {}

    /**
     * @param  bool  $data  Postgres is stopped: also record the source cluster's checkpoint
     * @return array{containers: array<string, array{service: string, started_at: string|null}>, checkpoint: string|null, data: bool}
     */
    public function record(LegacyInstall $legacy, string $postgresImage, string $uuid, bool $data): array
    {
        $containers = [];
        foreach (LegacyContainers::find($this->docker, $legacy->project, $legacy->checkout) as $service => $container) {
            $containers[$container->id] = ['service' => $service, 'started_at' => $container->startedAt];
        }

        return ['containers' => $containers, 'checkpoint' => $data ? $this->checkpoint($legacy, $postgresImage, $uuid) : null, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $fence  what record() returned (journaled)
     *
     * @throws InstanceException exit 4 `legacy_restarted`
     */
    public function assert(LegacyInstall $legacy, array $fence, string $postgresImage, string $uuid): void
    {
        $data = ($fence['data'] ?? false) === true;
        $recorded = is_array($fence['containers'] ?? null) ? $fence['containers'] : [];
        $problems = [];
        $seen = [];
        foreach (LegacyContainers::find($this->docker, $legacy->project, $legacy->checkout) as $service => $container) {
            $seen[] = $container->id;
            $problems = [...$problems, ...$this->problems($service, $container, $recorded[$container->id] ?? null, $data)];
        }
        foreach (array_diff(array_keys($recorded), $seen) as $id) {
            $problems[] = ($recorded[$id]['service'] ?? $id).' was removed or recreated';
        }
        if ($data && $problems === [] && $this->checkpoint($legacy, $postgresImage, $uuid) !== ($fence['checkpoint'] ?? null)) {
            $problems[] = 'its database files changed since Postgres stopped';
        }
        if ($problems !== []) {
            throw new InstanceException(
                '1.0 was started again after adopt stopped it ('.implode('; ', $problems).'): it may hold data the copy does not have.',
                ExitCode::HealthCheckFailed, 'legacy_restarted',
                'Run adopt again: it stops 1.0 and copies its current data. Do not start 1.0 while adopt runs.',
                true, ['problems' => $problems],
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $recorded
     * @return list<string>
     */
    private function problems(string $service, ContainerInfo $container, ?array $recorded, bool $data): array
    {
        if ($recorded === null) {
            return ["{$service} is a new container"];
        }
        $problems = [];
        if (($recorded['started_at'] ?? null) !== $container->startedAt) {
            $problems[] = "{$service} was started again";
        }
        if ($container->running() && ($data || ! in_array($service, LegacyInstall::INFRASTRUCTURE, true))) {
            $problems[] = "{$service} runs";
        }

        return $problems;
    }

    /** The source cluster's state and latest checkpoint, as `pg_controldata` reads them from 1.0's volume (read-only). */
    private function checkpoint(LegacyInstall $legacy, string $postgresImage, string $uuid): string
    {
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $postgresImage, command: [VolumeCopy::DATA_PATH], labels: Labels::forInstance($uuid, DatabaseProbe::ROLE),
            volumes: [$legacy->volumes[LegacyInstall::DATA_VOLUME].':'.VolumeCopy::DATA_PATH.':ro'], network: 'none', user: '0:0', entrypoint: 'pg_controldata',
        )->hardened(ContainerSpec::READ_ANY), null, 120.0);
        $state = preg_match('/^Database cluster state:\s*(.+)$/m', $outcome->stdout, $match) === 1 ? trim($match[1]) : null;
        $location = preg_match('/^Latest checkpoint location:\s*(\S+)/m', $outcome->stdout, $match) === 1 ? $match[1] : null;
        if (! $outcome->successful() || $state === null || $location === null) {
            throw new InstanceException('The state of 1.0\'s database files cannot be read: '.$outcome->stderrTail(2), ExitCode::HealthCheckFailed, 'legacy_unreadable', 'Read the output; 1.0\'s volume was only read.', true);
        }

        return $state.'@'.$location;
    }
}
