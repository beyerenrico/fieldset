<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeGuard;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The staged files of a child (KTD19 steps 6 and 7, plan U18, ADR 0086, review U18 S2): the target's, or for `fieldset rollback`
 * the previous image's. `validate()`: only release files (ReleaseFiles: the compose file, env files of its services, the
 * edge's files, the backup service's `instance.json`; `runtime/` is ignored) may be staged, `docker compose config` must
 * accept the project, its app service must run the expected app digest, every service an image the release pins, and
 * the compose file must pass the installed release's ComposeGuard (defence in depth: the release is signed, but its
 * files are started by the process that holds the socket). `install()`: those files replace the live ones with fieldset's
 * modes (env files 0600 whatever the child left), never the runtime directory (the gate stays as the supervisor set it);
 * env files of services the stage no longer renders go. Step 7 starts every staged service except queue, scheduler and
 * backup (they start after the commit) and the catcher and the quick tunnel, which are not recreated by an upgrade (a
 * tunnel restart would change its host name).
 */
final readonly class StagedRelease
{
    /** Never started by step 7. */
    public const array LATER = [AppRoles::QUEUE, AppRoles::SCHEDULER, BackupService::NAME, MailpitService::NAME, TunnelService::NAME];

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker, private ReleaseFiles $files) {}

    /**
     * @param  array<string, string>  $digests  the image references the release pins (role => reference)
     *
     * @throws InstanceException exit 5 `staged_invalid` (the caller rolls back or goes back)
     */
    public function validate(InstanceState $state, string $stage, array $digests): void
    {
        $root = $stage.'/'.StageRenderer::FILES;
        $staged = InstanceDirectory::open($root);
        $document = UpgradeCompose::document((string) $staged->read(InstanceDirectory::COMPOSE_FILE));
        $services = array_map(strval(...), array_keys(is_array($document['services'] ?? null) ? $document['services'] : []));
        $foreign = array_values(array_filter(StageRenderer::list($root), static fn (string $file): bool => ! ReleaseFiles::runtime($file) && ! ReleaseFiles::allowed($file, $services)));
        if ($foreign !== []) {
            $this->refuse('The stage holds files fieldset never takes from a release: '.implode(', ', array_slice($foreign, 0, 10)).'.', ['files' => $foreign]);
        }
        try {
            $model = $this->docker->composeConfig(new ComposeProject($state->project(), $root.'/'.InstanceDirectory::COMPOSE_FILE, $root));
        } catch (DockerCommandFailed $e) {
            $this->refuse('The staged compose file is invalid: '.$e->outcome->stderrTail(3), ['output' => $e->outcome->stderrTail(10)], $e);
        }
        $image = $document['services'][AppRoles::APP]['image'] ?? null;
        if ($image !== ($digests['app'] ?? null) || $model === []) {
            $this->refuse('The staged compose file does not run the expected app image.', ['image' => is_string($image) ? $image : null]);
        }
        $this->assertContained($document, array_values($digests), $staged, $services);
        $this->assertOwnVolumes($state, $document);
    }

    /**
     * @return list<string> the services step 7 starts
     */
    public function install(string $stage): array
    {
        $root = $stage.'/'.StageRenderer::FILES;
        $staged = InstanceDirectory::open($root);
        $compose = (string) $staged->read(InstanceDirectory::COMPOSE_FILE);
        $services = array_map(strval(...), array_keys(UpgradeCompose::document($compose)['services'] ?? []));
        foreach (StageRenderer::list($root) as $file) {
            if ($file !== InstanceDirectory::COMPOSE_FILE && ReleaseFiles::allowed($file, $services)) {
                $this->files->put($file, (string) $staged->read($file));
            }
        }
        $env = new EnvFiles($this->directory);
        foreach (array_diff($env->services(), $services) as $stale) {
            $env->remove($stale);
        }
        $this->files->put(InstanceDirectory::COMPOSE_FILE, $compose);

        return array_values(array_diff($services, self::LATER));
    }

    /**
     * ComposeGuard on what the child rendered, its env files included (no RUN_MIGRATIONS, review V3), and every service's
     * image one the release pins.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $images
     * @param  list<string>  $services
     *
     * @throws InstanceException exit 5 `staged_invalid`
     */
    private function assertContained(array $document, array $images, InstanceDirectory $staged, array $services): void
    {
        $env = new EnvFiles($staged);
        $environments = [];
        foreach ($services as $service) {
            $environments[$service] = $env->read($service);
        }
        try {
            ComposeGuard::assertSafe($document, $environments);
        } catch (InstanceException $e) {
            $this->refuse('The staged compose file is not acceptable: '.$e->getMessage(), [], $e);
        }
        foreach (is_array($document['services'] ?? null) ? $document['services'] : [] as $name => $service) {
            $image = is_array($service) ? ($service['image'] ?? null) : null;
            if (! is_string($image) || ! in_array($image, $images, true)) {
                $this->refuse("The staged service [{$name}] runs an image the release does not pin: ".(is_string($image) ? $image : 'none').'.', ['service' => (string) $name]);
            }
        }
    }

    /**
     * An external volume (a restored storage volume) must be one of this instance's: named with its project and labelled
     * with its UUID (review V3); another instance's volume would otherwise be mounted.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws InstanceException exit 5 `staged_invalid`
     */
    private function assertOwnVolumes(InstanceState $state, array $document): void
    {
        $own = null;
        foreach (is_array($document['volumes'] ?? null) ? $document['volumes'] : [] as $key => $volume) {
            if (! is_array($volume) || ($volume['external'] ?? false) === false) {
                continue;
            }
            $own ??= array_map(static fn (DockerResource $resource): string => $resource->name, $this->docker->volumes(Labels::forInstance($state->uuid())));
            $name = is_string($volume['name'] ?? null) ? $volume['name'] : (string) $key;
            if (! str_starts_with($name, $state->project().'_') || ! in_array($name, $own, true)) {
                $this->refuse("The staged compose file mounts the external volume {$name}, which is not one of this instance's.", ['volume' => $name]);
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function refuse(string $message, array $data = [], ?Throwable $previous = null): never
    {
        throw new InstanceException($message, ExitCode::RolledBack, 'staged_invalid', null, true, $data, $previous);
    }
}
