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

    /**
     * @param  string|null  $previous  the volume the reset replaced, removed by name even without the label (V8)
     * @return array{removed: list<string>, left_behind: list<string>}
     */
    public function remove(InstanceState $state, ComposeProject $project, string $keep, ?string $previous = null): array
    {
        $removed = [];
        $left = [];
        foreach ($this->volumes($state, $keep, $previous) as $volume) {
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
                $this->databases->drop($project, $database, self::role($database)); // with its restricted role (V5)
                $removed[] = "database {$database}";
            } catch (Throwable) {
                $left[] = "database {$database}";
            }
        }

        return ['removed' => $removed, 'left_behind' => $left];
    }

    /**
     * The restricted LOGIN role an unauthenticated restore or drill made for a scratch database (RestoreNames, RestoreDrill):
     * `<db>_restore_<id>` → `fieldset_restore_<id>`, `restore_drill_<id>` → `fieldset_drill_<id>`; a put-aside database has none.
     */
    public static function role(string $database): ?string
    {
        return match (true) {
            preg_match('/^restore_drill_([0-9a-f]{12})$/D', $database, $match) === 1 => 'fieldset_drill_'.$match[1],
            preg_match('/(?<!pre)_restore_([0-9a-f]{12})$/D', $database, $match) === 1 => 'fieldset_restore_'.$match[1],
            default => null,
        };
    }

    /** @return list<string> the instance's labelled storage volumes and the replaced one (by name), but the one in use */
    private function volumes(InstanceState $state, string $keep, ?string $previous): array
    {
        $prefix = $state->project().'_'.AppRoles::STORAGE_VOLUME;
        $labelled = array_map(static fn (DockerResource $resource): string => $resource->name, $this->docker->volumes([Labels::INSTANCE => $state->uuid()]));
        $named = $previous === null ? [] : array_map(static fn (DockerResource $resource): string => $resource->name, array_filter($this->docker->volumes([]), static fn (DockerResource $resource): bool => $resource->name === $previous));
        $names = array_values(array_unique([...$labelled, ...$named]));

        return array_values(array_filter($names, static fn (string $name): bool => $name !== $keep && ($name === $previous || $name === $prefix || str_starts_with($name, $prefix.'-'))));
    }
}
