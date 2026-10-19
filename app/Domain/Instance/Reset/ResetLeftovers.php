<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Platform\Instance\Restore\DatabaseAdmin;
use Throwable;

/**
 * What a reset removes besides the database (plan U23, ADR 0088; U23 review A4/S5, A6): the instance's storage volumes
 * other than the fresh one (the one the reset replaced, and older ones an incomplete restore or reset clean-up left) and
 * the databases a restore or drill left (`<db>_restore_<id>`, `<db>_pre_restore_<id>`, `restore_drill_<id>`: the names
 * `instance:restore-db` alone may drop). Both are listed, not remembered, so a repeated clean-up finds only what is still
 * there (a volume removed by a killed attempt is no false warning).
 */
final readonly class ResetLeftovers
{
    public function __construct(private DockerGateway $docker, private RestoreDatabases $databases, private RestoreStorage $storage) {}

    /** @return array{removed: list<string>, left_behind: list<string>} */
    public function remove(InstanceState $state, ComposeProject $project, string $keep): array
    {
        $removed = [];
        $left = [];
        foreach ($this->volumes($state, $keep) as $volume) {
            if ($this->storage->remove($volume)) {
                $removed[] = "volume {$volume}";
            } else {
                $left[] = "volume {$volume}";
            }
        }
        $live = InstanceSettings::of($state)->dbDatabase();
        try {
            $databases = array_values(array_filter($this->databases->list($project, ''), static fn (string $name): bool => $name !== $live && preg_match(DatabaseAdmin::DROPPABLE, $name) === 1));
        } catch (Throwable) {
            return ['removed' => $removed, 'left_behind' => [...$left, 'restore databases (they could not be listed)']];
        }
        foreach ($databases as $database) {
            try {
                $this->databases->drop($project, $database);
                $removed[] = "database {$database}";
            } catch (Throwable) {
                $left[] = "database {$database}";
            }
        }

        return ['removed' => $removed, 'left_behind' => $left];
    }

    /** @return list<string> the instance's labelled storage volumes but the one in use */
    private function volumes(InstanceState $state, string $keep): array
    {
        $prefix = $state->project().'_'.AppRoles::STORAGE_VOLUME;
        $names = array_map(static fn (DockerResource $resource): string => $resource->name, $this->docker->volumes([Labels::INSTANCE => $state->uuid()]));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== $keep && ($name === $prefix || str_starts_with($name, $prefix.'-'))));
    }
}
