<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * Resources an install must not touch (§6.3, R63). A compose project of the same name whose containers,
 * volumes or networks carry another instance's label, or none, makes install exit 7 and names them: fsa takes
 * over nothing it did not create. Containers of other instances that ran from this very directory are orphans
 * of a hand-deleted directory: they are named in a warning, with `fsa uninstall --orphans <uuid>` as the fix.
 */
final readonly class ProjectConflicts
{
    public const string WORKING_DIR_LABEL = 'com.docker.compose.project.working_dir';

    public function __construct(private DockerGateway $docker) {}

    /** @throws InstanceException exit 7 */
    public function assertFree(string $project, string $uuid): void
    {
        $filter = [Labels::COMPOSE_PROJECT => $project];
        $foreign = [];
        foreach ($this->docker->containers($filter) as $container) {
            if (($container->labels[Labels::INSTANCE] ?? null) !== $uuid) {
                $foreign[] = "container {$container->name}";
            }
        }
        $resources = [...$this->docker->volumes($filter), ...$this->docker->networks($filter)];
        foreach ($resources as $resource) {
            if (($resource->labels[Labels::INSTANCE] ?? null) !== $uuid) {
                $foreign[] = ($resource->kind === DockerResource::VOLUME ? 'volume ' : 'network ').$resource->name;
            }
        }
        if ($foreign !== []) {
            throw new InstanceException(
                "The compose project {$project} exists and does not belong to this instance: ".implode(', ', $foreign).'.',
                ExitCode::Incompatible,
                'project_conflict',
                'fsa never takes over resources it did not create. Remove them yourself if they are leftovers, or install into another directory.',
                false,
                ['project' => $project, 'resources' => $foreign],
            );
        }
    }

    /** @return list<string> UUIDs of other instances whose containers ran from this directory */
    public function orphans(string $directory, string $uuid): array
    {
        $uuids = [];
        foreach ($this->docker->containers([Labels::INSTANCE => null, self::WORKING_DIR_LABEL => $directory]) as $container) {
            $other = $container->labels[Labels::INSTANCE] ?? '';
            if ($other !== '' && $other !== $uuid) {
                $uuids[] = $other;
            }
        }

        return array_values(array_unique($uuids));
    }
}
