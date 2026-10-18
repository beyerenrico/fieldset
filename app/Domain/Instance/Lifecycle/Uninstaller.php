<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\ProjectConflicts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InvalidState;

/**
 * `fsa uninstall` (R44, R63, ADR 0072). Only resources labelled with the instance UUID are removed, found by
 * label rather than through the compose file, so nothing of another project is touched even when the names
 * collide: containers (forced), then networks, then volumes. Then the files of the instance directory go, all
 * but `backups/` with --keep-backups. The directory itself stays (empty): it is the ops container's bind mount
 * and cannot be removed from inside it. If a resource cannot be removed, the files stay, so the command can be
 * run again (exit 1).
 */
final readonly class Uninstaller
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private InstanceLock $lock,
        private OperationJournal $journal,
        private ContainerIdentity $identity,
    ) {}

    public function uninstall(bool $keepBackups, HostFacts $facts): CommandResult
    {
        $uuid = $this->uuid();
        $handle = $this->lock->acquire($this->identity->holder('fsa', $facts->callerUid()), 'uninstall');

        // The lock stays until everything but itself is gone, so no other operation writes into a half-deleted
        // directory (the marker goes first among the files, after it no fsa command accepts the directory).
        try {
            $removed = $uuid === null ? ['containers' => [], 'networks' => [], 'volumes' => []] : $this->removeResources($uuid);
            $deleted = $this->deleteFiles($keepBackups, [...array_filter([$facts->nonce, $facts->openUrlFile]), InstanceDirectory::LOCKS_DIR]);
        } finally {
            $this->lock->release($handle);
        }
        $this->directory->files()->delete(InstanceDirectory::LOCKS_DIR);
        $result = CommandResult::ok('uninstall', [
            'uuid' => $uuid, 'removed' => $removed, 'dir' => $this->directory->root, 'deleted_files' => $deleted,
            'kept' => $keepBackups ? [InstanceDirectory::BACKUPS_DIR] : [],
        ], $deleted !== [] || array_merge(...array_values($removed)) !== []);

        return $uuid !== null ? $result : $result->withWarning('instance_id_unknown', 'No installation ID was found (no state, journal, compose file or container of this directory); only the files were removed.', 'If containers of this instance remain, remove them with `fsa uninstall --orphans <uuid>` from another instance.');
    }

    public function orphans(string $uuid): CommandResult
    {
        if (preg_match(self::UUID, $uuid) !== 1) {
            throw new InvalidInput('--orphans takes the UUID of an installation, as `fsa install` names it.', 'invalid_flag');
        }
        if ($uuid === $this->uuid()) {
            throw new InvalidInput('That is this instance; run `fsa uninstall` without --orphans to remove it.', 'invalid_flag');
        }
        $removed = $this->removeResources($uuid);
        $result = CommandResult::ok('uninstall', ['uuid' => $uuid, 'removed' => $removed, 'orphans' => true], array_merge(...array_values($removed)) !== []);

        return $result->changed ? $result : $result->withWarning('nothing_found', "No container, volume or network carries installation {$uuid}.");
    }

    /**
     * The instance UUID: from the state; when that is missing or unreadable, from an install that stopped before
     * writing it, the compose file's labels, or the containers that ran from this directory (if they agree).
     */
    private function uuid(): ?string
    {
        foreach ([$this->fromState(...), $this->fromJournal(...), $this->fromComposeFile(...), $this->fromContainers(...)] as $source) {
            $uuid = $source();
            if (is_string($uuid) && preg_match(self::UUID, $uuid) === 1) {
                return $uuid;
            }
        }

        return null;
    }

    private function fromState(): ?string
    {
        try {
            return $this->directory->hasState() ? $this->directory->state()->uuid() : null;
        } catch (InvalidState) {
            return null;
        }
    }

    private function fromJournal(): mixed
    {
        try {
            $current = $this->journal->current();
        } catch (InvalidState) {
            return null;
        }

        return $current?->operation() === Installer::OPERATION ? ($current->phaseData('doctor')['uuid'] ?? null) : null;
    }

    private function fromComposeFile(): mixed
    {
        $services = ComposeFile::document($this->directory)['services'] ?? [];
        $first = is_array($services) ? reset($services) : null;

        return is_array($first) && is_array($first['labels'] ?? null) ? ($first['labels'][Labels::INSTANCE] ?? null) : null;
    }

    private function fromContainers(): ?string
    {
        $uuids = array_unique(array_map(
            static fn (ContainerInfo $container): string => $container->labels[Labels::INSTANCE] ?? '',
            $this->docker->containers([Labels::INSTANCE => null, ProjectConflicts::WORKING_DIR_LABEL => $this->directory->root]),
        ));

        return count($uuids) === 1 ? reset($uuids) : null;
    }

    /** @return array{containers: list<string>, networks: list<string>, volumes: list<string>} */
    private function removeResources(string $uuid): array
    {
        $labels = [Labels::INSTANCE => $uuid];
        $removed = ['containers' => [], 'networks' => [], 'volumes' => []];

        try {
            foreach ($this->docker->containers($labels) as $container) {
                $this->docker->removeContainer($container->id);
                $removed['containers'][] = $container->name;
            }
            foreach ($this->docker->networks($labels) as $network) {
                $this->docker->removeNetwork($network->name);
                $removed['networks'][] = $network->name;
            }
            foreach ($this->docker->volumes($labels) as $volume) {
                $this->docker->removeVolume($volume->name);
                $removed['volumes'][] = $volume->name;
            }
        } catch (DockerCommandFailed $e) {
            throw new InstanceException('Not every resource could be removed: '.$e->getMessage(), ExitCode::InternalError, 'uninstall_incomplete', 'Run `fsa uninstall` again; the instance files are kept until everything is gone.', true, ['removed' => $removed, 'stderr' => $e->outcome->stderrTail(3)], $e);
        }

        return $removed;
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
