<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\ProjectConflicts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;

/**
 * Which installation an instance directory belongs to, for `fsa uninstall` (R44, R63, ADR 0072): the UUID from
 * the state; when that is missing or unreadable, from an install that stopped before writing it, the compose
 * file's labels, or the containers that ran from this directory (if they agree).
 *
 * Without a UUID uninstall must not delete the files (APP_KEY, database password) while labelled resources of
 * this directory remain: they would be left without the secrets that open them. `assertNothingTied()` refuses
 * with exit 7 and names the candidates.
 */
final readonly class InstanceIdResolver
{
    public const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private OperationJournal $journal,
    ) {}

    public function resolve(): ?string
    {
        foreach ([$this->fromState(...), $this->fromJournal(...), $this->fromComposeFile(...), $this->fromContainers(...)] as $source) {
            $uuid = $source();
            if (is_string($uuid) && preg_match(self::UUID, $uuid) === 1) {
                return $uuid;
            }
        }

        return null;
    }

    /** The readable state, or null (missing or invalid). */
    public function state(): ?InstanceState
    {
        try {
            return $this->directory->hasState() ? $this->directory->state() : null;
        } catch (InvalidState) {
            return null;
        }
    }

    /**
     * For an unknown UUID: refuses (exit 7) while containers that ran from this directory, or volumes of a
     * compose project named after it, carry an installation label.
     *
     * @throws InstanceException
     */
    public function assertNothingTied(): void
    {
        $containers = $this->docker->containers([Labels::INSTANCE => null, ProjectConflicts::WORKING_DIR_LABEL => $this->directory->root]);
        $volumes = array_values(array_filter($this->docker->volumes([Labels::INSTANCE => null]), $this->namedAfterThisDirectory(...)));
        if ($containers === [] && $volumes === []) {
            return;
        }
        $uuids = array_values(array_unique(array_map(
            static fn (ContainerInfo|DockerResource $item): string => $item->labels[Labels::INSTANCE] ?? '',
            [...$containers, ...$volumes],
        )));

        throw new InstanceException(
            'No installation ID could be determined (state, install journal and compose file are missing or unreadable, or containers of several installations ran from here), but labelled resources of this directory remain: '.implode(', ', $uuids).'.',
            ExitCode::Incompatible,
            'instance_id_unknown',
            'Nothing was deleted. Restore state.json, or check each candidate (`docker ps -a --filter label='.Labels::INSTANCE.'=<uuid>`, `docker volume ls --filter label='.Labels::INSTANCE.'=<uuid>`) and remove its resources yourself, then run `fsa uninstall` again.',
            false,
            ['candidates' => $uuids, 'containers' => array_map(static fn (ContainerInfo $c): string => $c->name, $containers), 'volumes' => array_map(static fn (DockerResource $v): string => $v->name, $volumes)],
        );
    }

    private function namedAfterThisDirectory(DockerResource $volume): bool
    {
        $uuid = $volume->labels[Labels::INSTANCE] ?? '';

        return preg_match(self::UUID, $uuid) === 1
            && ($volume->labels[Labels::COMPOSE_PROJECT] ?? null) === InstanceState::projectFor(InstanceState::slugFor($this->directory->root), $uuid);
    }

    private function fromState(): ?string
    {
        return $this->state()?->uuid();
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
}
