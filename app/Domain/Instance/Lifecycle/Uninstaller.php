<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Adopt\AdoptedInstance;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\ProjectConflicts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa uninstall` (R44, R63, §13.3, ADR 0072, ADR 0073). Only resources labelled with the instance UUID are removed,
 * found by label rather than through the compose file, so nothing of another project is touched even when the
 * names collide: containers (forced), then networks, then volumes. Then the files of the instance directory go.
 *
 * - --keep-backups keeps `backups/` and first writes a final backup into it (FinalBackup: database dump, storage
 *   archive, APP_KEY); if that fails nothing is removed (exit 4). Without it, data and backups are deleted.
 * - An adopted instance (plan U19, ADR 0087) gets the final backup and keeps `backups/` without --keep-backups, unless
 *   --no-backup: its 1.0 checkout holds the data only up to the adopt. The result names the writes made since adopt
 *   (AdoptedInstance) and the command that brings 1.0 back; the 1.0 volumes were never this instance's.
 * - Without a determinable UUID the files go only when no labelled resource of this directory is left (exit 7).
 * - If a resource cannot be removed, the files stay, so the command can be run again (exit 4, `changed` true).
 * - The directory itself stays (empty): it is the ops container's bind mount and cannot be removed from inside it.
 * - --orphans <uuid> removes the leftovers of another installation that install named for this directory, never
 *   while one of its containers runs (exit 7), and never its volumes while it has containers elsewhere.
 */
final readonly class Uninstaller
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private InstanceLock $lock,
        private ContainerIdentity $identity,
        private InstanceIdResolver $ids,
        private FinalBackup $backup,
        private ProjectConflicts $conflicts,
        private AdoptedInstance $adopted,
    ) {}

    /** @param  bool  $noBackup  never write the final backup (an adopted instance gets one by default) */
    public function uninstall(bool $keepBackups, HostFacts $facts, bool $noBackup = false): CommandResult
    {
        $uuid = $this->ids->resolve();
        if ($uuid === null) {
            $this->ids->assertNothingTied();
        }
        $state = $this->ids->state();
        $adopted = $this->adopted->current($state);
        $keepBackups = $keepBackups || ($adopted !== null && ! $noBackup);
        $handle = $this->lock->acquire($this->identity->holder('fsa', $facts->callerUid()), 'uninstall');

        // The lock stays until everything but itself is gone, so no other operation writes into a half-deleted
        // directory (the marker goes first among the files, after it no fsa command accepts the directory).
        try {
            $volumes = $uuid === null ? [] : $this->docker->volumes([Labels::INSTANCE => $uuid]);
            $final = $keepBackups && ! $noBackup ? $this->backup->write($this->ids->state(), $uuid, $volumes, withKey: $adopted === null) : null;
            $writes = $adopted !== null && $state instanceof InstanceState ? $this->adopted->writesSince($state) : null;
            $removed = $uuid === null ? ['containers' => [], 'networks' => [], 'volumes' => []] : $this->removeResources($uuid, $final !== null);
            $deleted = $this->deleteFiles($keepBackups, [...array_filter([$facts->nonce, $facts->openUrlFile]), InstanceDirectory::LOCKS_DIR]);
        } finally {
            $this->lock->release($handle);
        }
        $this->directory->files()->delete(InstanceDirectory::LOCKS_DIR);

        $result = $this->result($uuid, $removed, $deleted, $keepBackups, $final);
        $result = $adopted === null ? $result : $this->adoptedResult($result, $adopted, $writes, $final);

        return ! $this->holdsCertificates($volumes, $state) ? $result : $result->withWarning('certificates_removed', self::certificateWarning($this->domain($state)), 'Use --acme-staging for trial installs; reinstalling the same domain often counts against the CA\'s duplicate-certificate limit.');
    }

    /**
     * @param  array<string, mixed>  $record  the `adopted` history entry
     * @param  array{audit_entries: int, rows: array<string, int>, any: bool}|null  $writes
     * @param  array{dir: string, files: list<string>}|null  $final
     */
    private function adoptedResult(CommandResult $result, array $record, ?array $writes, ?array $final): CommandResult
    {
        $data = AdoptedInstance::uninstallData($record, $writes);
        $result = $result->withData(['adopted' => $data])
            ->withWarning('restart_1_0', 'The 1.0 install this instance was adopted from is stopped; its volumes were never changed.'.AdoptedInstance::jobsNotice($record), 'Bring it back with: '.$data['restart_1_0']);
        if ($writes === null) {
            return $result->withWarning('writes_since_adopt_unknown', 'The writes made since adopt could not be counted (no baseline in backups/ledger.log, or Postgres did not answer).', $final === null ? 'Without a final backup they are gone with the instance.' : "Whatever was written is in the final backup {$final['dir']}.");
        }
        if (! $writes['any']) {
            return $result;
        }
        $where = $final === null ? 'They are in no backup (--no-backup).' : "They are in the final backup {$final['dir']}.";

        return $result->withWarning('writes_since_adopt', "Data written since adopt ({$writes['audit_entries']} audit entries, row changes ".json_encode($writes['rows']).') is not in the 1.0 install. '.$where, 'Restore it into a 2.0 instance with `fsa install --from-backup` if you need it.');
    }

    /**
     * The certificate warning for the confirmation prompt, when this instance's certificate volume exists (whatever
     * the mode now: after `config set mode local` the volume, and its certificates, are still there; ADR 0078).
     */
    public function certificateNotice(): ?string
    {
        $uuid = $this->ids->resolve();
        $state = $this->ids->state();
        $volumes = $uuid === null ? [] : $this->docker->volumes([Labels::INSTANCE => $uuid]);

        return $this->holdsCertificates($volumes, $state) ? self::certificateWarning($this->domain($state)) : null;
    }

    /** The certificate volume goes with the instance (plan Risks, ADR 0075). */
    public static function certificateWarning(?string $domain): string
    {
        $what = $domain === null ? 'The certificates this instance obtained in domain mode were' : "The certificates for {$domain} were";

        return "{$what} removed with the instance. Let's Encrypt allows only a few identical certificates per week, so a new install of the same domain may be refused for a while.";
    }

    /**
     * Whether the edge's certificate volume is among the instance's labelled volumes: by its name when the project is
     * known (EdgeService::volumeName), else by the `_edge-data` suffix Compose gives it.
     *
     * @param  list<DockerResource>  $volumes
     */
    private function holdsCertificates(array $volumes, ?InstanceState $state): bool
    {
        $name = $state instanceof InstanceState ? EdgeService::volumeName($state->project()) : null;

        return array_any($volumes, static fn (DockerResource $volume): bool => $name === null ? str_ends_with($volume->name, '_'.EdgeService::CERTIFICATE_VOLUME) : $volume->name === $name);
    }

    private function domain(?InstanceState $state): ?string
    {
        return $state instanceof InstanceState ? InstanceSettings::of($state)->domain() : null;
    }

    public function orphans(string $uuid): CommandResult
    {
        if (preg_match(InstanceIdResolver::UUID, $uuid) !== 1) {
            throw new InvalidInput('--orphans takes the UUID of an installation, as `fsa install` names it.', 'invalid_flag');
        }
        $own = $this->ids->resolve();
        if ($uuid === $own) {
            throw new InvalidInput('That is this instance; run `fsa uninstall` without --orphans to remove it.', 'invalid_flag');
        }
        if (! in_array($uuid, $this->conflicts->orphans($this->directory->root, $own ?? ''), true)) {
            throw new InvalidInput("Installation {$uuid} has no container that ran from this directory; fsa removes only the orphans `fsa install` names here.", 'not_an_orphan', 'If its resources are leftovers, check them (`docker ps -a --filter label='.Labels::INSTANCE."={$uuid}`, `docker volume ls --filter label=".Labels::INSTANCE."={$uuid}`) and remove them yourself.");
        }
        $containers = $this->docker->containers([Labels::INSTANCE => $uuid]);
        $this->refuseLive($uuid, $containers);
        $elsewhere = array_values(array_filter($containers, fn (ContainerInfo $c): bool => ($c->labels[ProjectConflicts::WORKING_DIR_LABEL] ?? null) !== $this->directory->root));
        $removed = $this->removeResources($uuid, false, $elsewhere === []);
        $result = CommandResult::ok('uninstall', ['uuid' => $uuid, 'removed' => $removed, 'orphans' => true], array_merge(...array_values($removed)) !== []);

        return $elsewhere === [] ? $result : $result->withWarning(
            'orphan_volumes_kept',
            "Installation {$uuid} still has containers outside this directory (".implode(', ', array_map(static fn (ContainerInfo $c): string => $c->name, $elsewhere)).'): only its containers that ran from here were removed; its volumes and networks were kept.',
            'If that installation was moved, manage it from its new directory with its own `fsa`.',
        );
    }

    /**
     * @param  list<ContainerInfo>  $containers
     *
     * @throws InstanceException exit 7 while the installation runs (it may have moved rather than been deleted)
     */
    private function refuseLive(string $uuid, array $containers): void
    {
        $running = array_values(array_filter($containers, static fn (ContainerInfo $c): bool => $c->running()));
        if ($running !== []) {
            throw new InstanceException(
                "Installation {$uuid} is running (".implode(', ', array_map(static fn (ContainerInfo $c): string => $c->name, $running)).'); its directory may have moved rather than been deleted.',
                ExitCode::Incompatible,
                'orphans_live',
                'Nothing was removed. Stop it from its own directory first (`fsa uninstall` there removes it completely), or leave it alone.',
                false,
                ['uuid' => $uuid, 'running' => array_map(static fn (ContainerInfo $c): string => $c->name, $running)],
            );
        }
    }

    /**
     * @param  bool  $backedUp  a final backup was written (a partial removal then also reports `changed`)
     * @param  bool  $withVolumes  false keeps networks and volumes (orphans with containers elsewhere)
     * @return array{containers: list<string>, networks: list<string>, volumes: list<string>}
     */
    private function removeResources(string $uuid, bool $backedUp, bool $withVolumes = true): array
    {
        $labels = [Labels::INSTANCE => $uuid];
        $removed = ['containers' => [], 'networks' => [], 'volumes' => []];

        try {
            foreach ($this->docker->containers([...$labels, ...($withVolumes ? [] : [ProjectConflicts::WORKING_DIR_LABEL => $this->directory->root])]) as $container) {
                $this->docker->removeContainer($container->id);
                $removed['containers'][] = $container->name;
            }
            foreach ($withVolumes ? $this->docker->networks($labels) : [] as $network) {
                $this->docker->removeNetwork($network->name);
                $removed['networks'][] = $network->name;
            }
            foreach ($withVolumes ? $this->docker->volumes($labels) : [] as $volume) {
                $this->docker->removeVolume($volume->name);
                $removed['volumes'][] = $volume->name;
            }
        } catch (DockerCommandFailed $e) {
            $changed = $backedUp || array_merge(...array_values($removed)) !== [];

            throw new InstanceException('Not every resource could be removed: '.$e->getMessage(), ExitCode::HealthCheckFailed, 'uninstall_incomplete', 'Run the same `fsa uninstall` command again to finish; the instance files are kept until everything is gone.', true, ['removed' => $removed, 'stderr' => $e->outcome->stderrTail(3)], $e, $changed);
        }

        return $removed;
    }

    /**
     * @param  array{containers: list<string>, networks: list<string>, volumes: list<string>}  $removed
     * @param  list<string>  $deleted
     * @param  array{dir: string, files: list<string>}|null  $final
     */
    private function result(?string $uuid, array $removed, array $deleted, bool $keepBackups, ?array $final): CommandResult
    {
        $result = CommandResult::ok('uninstall', [
            'uuid' => $uuid, 'removed' => $removed, 'dir' => $this->directory->root, 'deleted_files' => $deleted,
            'kept' => $keepBackups ? [InstanceDirectory::BACKUPS_DIR] : [],
            'data_deleted' => true, 'backups_deleted' => ! $keepBackups, 'final_backup' => $final,
        ], $deleted !== [] || array_merge(...array_values($removed)) !== []);

        return $uuid !== null ? $result : $result->withWarning('instance_id_unknown', 'No installation ID was found (no state, journal, compose file or container of this directory) and no labelled resource is tied to it; only the files were removed.');
    }

    /**
     * @param  list<string>  $launcherFiles  the running launcher's nonce and hand-over files: it removes them itself
     * @return list<string>
     */
    private function deleteFiles(bool $keepBackups, array $launcherFiles): array
    {
        $files = $this->directory->files();
        $deleted = [];
        foreach ($files->list('') as $entry) {
            if (($keepBackups && $entry === InstanceDirectory::BACKUPS_DIR) || in_array($entry, $launcherFiles, true)) {
                continue;
            }
            $files->delete($entry);
            $deleted[] = $entry;
        }

        return $deleted;
    }
}
