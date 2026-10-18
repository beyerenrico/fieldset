<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * What `fsa status` reports (§7, R45, ADR 0056). Read-only: no file is written and no service is touched; the
 * only container it starts is the socket-less one-off that lists unused sign-in links (without tokens), and only
 * while the database runs. The last backup arrives with U16 (null until then).
 */
final readonly class StatusReport
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private OperationJournal $journal,
        private InstanceLock $lock,
        private AppCommands $app,
        private DoctorProbes $probes,
    ) {}

    public function build(): CommandResult
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        $operation = $this->journal->current();
        $free = $this->probes->diskFree($this->directory->root);
        $data = [
            'dir' => $this->directory->root,
            'installed' => $state instanceof InstanceState && InstallRecord::isInstalled($state),
            'operation' => $operation instanceof JournalRecord ? $this->operation($operation) : null,
            'lock' => $this->lock->current()?->toArray(),
            'disk' => ['free_gib' => $free === null ? null : round($free / 1024 ** 3, 1)],
            'last_backup' => null,
        ];
        $result = $state instanceof InstanceState ? $this->instance($state, $data) : CommandResult::ok('status', [...$data, 'health' => 'not_installed']);
        if ($operation instanceof JournalRecord) {
            return $result->withWarning('operation_unfinished', "`fsa {$operation->operation()}` stopped at phase ".($operation->lastStartedPhase() ?? 'none').'.', "Run `fsa {$operation->operation()}` again with the same flags to resume it.");
        }

        return $result;
    }

    /** @param  array<string, mixed>  $data */
    private function instance(InstanceState $state, array $data): CommandResult
    {
        $settings = InstanceSettings::of($state);
        $services = $this->services($state);
        $health = $this->health($services);
        $data = [...$data,
            'version' => $state->version(), 'mode' => $state->mode(), 'url' => $settings->instanceUrl(), 'project' => $state->project(),
            'port' => $settings->publishesAppPort() ? $settings->port() : null, 'health' => $health, 'services' => $services,
            'mail' => ['mode' => $settings->capturesMail() ? 'capture' : 'smtp', 'catcher_url' => $settings->capturesMail() ? 'http://localhost:'.$settings->mailUiPort() : null],
        ];
        $links = $this->links($state, $services);
        $result = CommandResult::ok('status', [...$data, 'sign_in_links' => $links]);
        if (! in_array($health, ['ok', 'not_started'], true)) {
            $bad = array_keys(array_filter($services, static fn (array $service): bool => $service['state'] !== 'running' || ! in_array($service['health'], [null, 'healthy'], true)));
            $result = $result->withWarning('services_unhealthy', 'Not running or unhealthy: '.implode(', ', $bad).'.', 'Read `fsa logs <service>`; `fsa doctor --post` checks the whole stack.');
        }

        return $links === null && $data['installed'] === true && $health === 'ok'
            ? $result->withWarning('sign_in_links_unavailable', 'The list of unused sign-in links could not be read.')
            : $result;
    }

    /** @return array<string, array{state: string, health: string|null}> */
    private function services(InstanceState $state): array
    {
        $expected = ComposeFile::services($this->directory);
        if ($expected === []) {
            return [];
        }

        try {
            $containers = ComposeFile::serviceContainers($this->docker->composePs(ComposeProject::forInstance($state, $this->directory)));
        } catch (DockerCommandFailed) {
            $containers = [];
        }
        $services = [];
        foreach ($expected as $service) {
            $container = array_find($containers, static fn (ContainerInfo $c): bool => $c->service() === $service);
            $services[$service] = ['state' => $container instanceof ContainerInfo ? $container->state : 'missing', 'health' => $container?->health];
        }

        return $services;
    }

    /**
     * @param  array<string, array{state: string, health: string|null}>  $services
     * @return array{count: int, links: list<mixed>}|null
     */
    private function links(InstanceState $state, array $services): ?array
    {
        if (($services['postgres']['state'] ?? null) !== 'running' || ! InstallRecord::isInstalled($state)) {
            return null;
        }
        $result = $this->app->loginLinks(ComposeProject::forInstance($state, $this->directory));
        $links = $result->data['links'] ?? null;

        if (! $result->ok || ! is_array($links)) {
            return null;
        }
        // Only these fields leave fsa, whatever the app side prints: a token or URL never reaches `data` (R50).
        $fields = array_flip(['id', 'email', 'kind', 'state', 'created_at', 'expires_at', 'used_at', 'used_ip', 'created_by', 'created_by_uid']);
        $rows = array_values(array_map(static fn (mixed $link): array => is_array($link) ? array_intersect_key($link, $fields) : [], $links));

        return ['count' => count($rows), 'links' => $rows];
    }

    /** @param  array<string, array{state: string, health: string|null}>  $services */
    private function health(array $services): string
    {
        if ($services === []) {
            return 'not_started';
        }
        $running = array_filter($services, static fn (array $service): bool => $service['state'] === 'running');
        $healthy = array_filter($running, static fn (array $service): bool => in_array($service['health'], [null, 'healthy'], true));

        return match (true) {
            $running === [] => 'stopped',
            count($healthy) === count($services) => 'ok',
            default => 'degraded',
        };
    }

    /** @return array<string, mixed> */
    private function operation(JournalRecord $record): array
    {
        return ['operation' => $record->operation(), 'last_phase' => $record->lastStartedPhase(), 'last_completed_phase' => $record->lastCompletedPhase(), 'started_at' => $record->startedAt()];
    }
}
