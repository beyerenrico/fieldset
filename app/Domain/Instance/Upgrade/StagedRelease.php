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
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * The target's staged files (KTD19 steps 6 and 7, plan U18, ADR 0086). `validate()`: `docker compose config` must accept
 * the staged project and its app service must run the target's digest. `install()`: the staged compose file, env files
 * and service files (Caddyfile, the backup service's `.instance.json`) replace the live ones, never the runtime
 * directory (the gate stays as the supervisor set it); env files of services the target no longer renders go. Step 7
 * starts every staged service except queue, scheduler and backup (they start after the commit) and the catcher and the
 * quick tunnel, which are not recreated by an upgrade (a tunnel restart would change its host name).
 */
final readonly class StagedRelease
{
    /** Never started by step 7. */
    public const array LATER = [AppRoles::QUEUE, AppRoles::SCHEDULER, BackupService::NAME, MailpitService::NAME, TunnelService::NAME];

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /**
     * @param  array<string, string>  $digests  the target's image references
     *
     * @throws InstanceException exit 5 `staged_invalid` (the caller rolls back)
     */
    public function validate(InstanceState $state, string $stage, array $digests): void
    {
        $files = $stage.'/'.StageRenderer::FILES;
        try {
            $model = $this->docker->composeConfig(new ComposeProject($state->project(), $files.'/'.InstanceDirectory::COMPOSE_FILE, $files));
        } catch (DockerCommandFailed $e) {
            throw new InstanceException('The target\'s staged compose file is invalid: '.$e->outcome->stderrTail(3), ExitCode::RolledBack, 'staged_invalid', null, true, ['output' => $e->outcome->stderrTail(10)], $e);
        }
        $staged = InstanceDirectory::open($files);
        $document = UpgradeCompose::document((string) $staged->read(InstanceDirectory::COMPOSE_FILE));
        $image = $document['services'][AppRoles::APP]['image'] ?? null;
        if ($image !== ($digests['app'] ?? null) || $model === []) {
            throw new InstanceException('The target\'s staged compose file does not run the target\'s app image.', ExitCode::RolledBack, 'staged_invalid', null, true, ['image' => is_string($image) ? $image : null]);
        }
        $this->assertContained($document);
    }

    /**
     * The installed release's own guard on what the target rendered (defence in depth: the target is a signed release,
     * but its files are started by the process that holds the socket): no service mounts the Docker socket, runs
     * privileged or sets RUN_MIGRATIONS (ComposeGuard).
     *
     * @param  array<string, mixed>  $document
     *
     * @throws InstanceException exit 5 `staged_invalid`
     */
    private function assertContained(array $document): void
    {
        try {
            ComposeGuard::assertSafe($document, []);
            foreach (is_array($document['services'] ?? null) ? $document['services'] : [] as $name => $service) {
                if (is_array($service) && ($service['privileged'] ?? false) === true) {
                    throw new InstanceException("The service [{$name}] would run privileged.", ExitCode::InternalError, 'unsafe_compose');
                }
            }
        } catch (InstanceException $e) {
            throw new InstanceException('The target\'s staged compose file is not acceptable: '.$e->getMessage(), ExitCode::RolledBack, 'staged_invalid', null, true, [], $e);
        }
    }

    /**
     * @return list<string> the services step 7 starts
     */
    public function install(string $stage): array
    {
        $root = $stage.'/'.StageRenderer::FILES;
        $staged = InstanceDirectory::open($root);
        foreach (StageRenderer::list($root) as $file) {
            if (str_starts_with($file, InstanceDirectory::RUNTIME_DIR.'/') || $file === InstanceDirectory::COMPOSE_FILE) {
                continue;
            }
            $dir = dirname($file);
            if ($dir !== '.') {
                $this->directory->ensureDirectory($dir, $dir === InstanceDirectory::ENV_DIR || $dir === InstanceDirectory::BACKUPS_DIR ? 0700 : 0755);
            }
            $this->directory->write($file, (string) $staged->read($file), fileperms($root.'/'.$file) & 0777);
        }
        $compose = (string) $staged->read(InstanceDirectory::COMPOSE_FILE);
        $services = array_keys(UpgradeCompose::document($compose)['services'] ?? []);
        $env = new EnvFiles($this->directory);
        foreach (array_diff($env->services(), $services) as $stale) {
            $env->remove($stale);
        }
        $this->directory->write(InstanceDirectory::COMPOSE_FILE, $compose, 0600);

        return array_values(array_diff(array_map(strval(...), $services), self::LATER));
    }
}
