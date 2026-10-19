<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Backup\BackupStatus;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Doctor\Checks\ProxyAddressCheck;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\ProxySetup;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * What `fsa status` reports (§7, R45, ADR 0056). Read-only: no file is written and no service is touched; the
 * only container it starts is the socket-less one-off that lists unused sign-in links (without tokens), and only
 * while the database runs. Backups (ADR 0084): `last_backup` (the newest successful run) and `backup` (schedule, next
 * run, encryption, sizes, safety backups, alerts as warnings), from the run ledger and `backups/`. Phone HTTPS (ADR
 * 0082): the local CA's trust page and fingerprint, and the quick tunnel, an expired one reported but left for the next
 * mutating command.
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
        private ProxySetup $proxySetup,
        private PhoneStatus $phone,
        private BackupStatus $backups,
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
            $command = OperationUnfinished::cutOff($operation);

            return $result->withWarning('operation_unfinished', "`{$command}` stopped at phase ".($operation->lastStartedPhase() ?? 'none').'.', OperationUnfinished::hint($operation));
        }

        return $result;
    }

    /** @param  array<string, mixed>  $data */
    private function instance(InstanceState $state, array $data): CommandResult
    {
        $settings = InstanceSettings::of($state);
        $services = $this->services($state);
        $health = $this->health($services);
        $proxy = $this->proxy($state);
        $phone = $this->phone->of($state);
        $backup = $this->backups->of($state);
        $data = [...$data,
            // The URL in effect: an active quick tunnel's, as `fsa tunnel` reports it, with the loopback URL beside it (ADR 0082).
            'version' => $state->version(), 'mode' => $state->mode(), 'url' => $phone['data']['tunnel']['url'] ?? $settings->instanceUrl(), 'local_url' => $settings->localUrl(), 'project' => $state->project(),
            'port' => $settings->publishesAppPort() ? $settings->port() : null, 'health' => $health, 'services' => $services,
            'mail' => MailSummary::of($state),
            'proxy' => $proxy,
            ...$phone['data'], // the local CA and the quick tunnel (ADR 0082)
            'last_backup' => $backup['data']['last_success'], 'backup' => $backup['data'],
        ];
        $links = $this->links($state, $services);
        $result = CommandResult::ok('status', [...$data, 'sign_in_links' => $links]);
        foreach ([...$phone['warnings'], ...$backup['warnings']] as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }
        if ($proxy !== null && $proxy['moved'] && $proxy['current'] !== null) {
            $result = $result->withWarning(ProxyAddressCheck::WARNING, 'The app trusts '.implode(', ', $proxy['trusted']).' as its proxy, but the proxy is at '.$proxy['current'].' now.', 'Run `fsa config set trusted-proxy auto`.');
        }
        if (! in_array($health, ['ok', 'not_started'], true)) {
            $bad = array_keys(array_filter($services, static fn (array $service): bool => $service['state'] !== 'running' || ! in_array($service['health'], [null, 'healthy'], true)));
            $result = $result->withWarning('services_unhealthy', 'Not running or unhealthy: '.implode(', ', $bad).'.', 'Read `fsa logs <service>`; `fsa doctor --post` checks the whole stack.');
        }

        return $links === null && $data['installed'] === true && $health === 'ok'
            ? $result->withWarning('sign_in_links_unavailable', 'The list of unused sign-in links could not be read.')
            : $result;
    }

    /**
     * Proxy mode: the setup, the trusted address and where the proxy is now (read-only, best effort; ADR 0078).
     *
     * @return array{setup: string, network: string|null, container: string|null, trusted: list<string>, current: string|null, moved: bool}|null
     */
    private function proxy(InstanceState $state): ?array
    {
        try {
            return $this->proxySetup->drift($state, ComposeProject::forInstance($state, $this->directory));
        } catch (Throwable) {
            $settings = InstanceSettings::of($state);
            $setup = $settings->proxy();

            return $setup === null ? null : ['setup' => $setup, 'network' => $settings->proxyNetwork(), 'container' => $settings->proxyContainer(), 'trusted' => $settings->trustedProxies(), 'current' => null, 'moved' => false];
        }
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
        return [
            'operation' => $record->operation(), 'last_phase' => $record->lastStartedPhase(), 'last_completed_phase' => $record->lastCompletedPhase(), 'started_at' => $record->startedAt(),
            'command' => OperationUnfinished::commandLine($record), 'options' => OperationUnfinished::options($record),
        ];
    }
}
