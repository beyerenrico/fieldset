<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\RenderedProject;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use Throwable;

/**
 * Applies and reverts a settings change on disk and in Docker (KTD6, ADR 0077). The state is the single authority:
 * apply saves the target state and renders runtime, env files, Caddyfile and compose file from it; revert saves the
 * previous state and renders again. Both end with `compose up --wait --remove-orphans` for the whole project:
 * Compose recreates exactly the services whose definition or env-file content changed (verified: an env-file change
 * recreates that service only) and removes services the mode no longer has (the edge after domain → local), while
 * unchanged services keep running. `affected()` reports the same set from the two renders.
 */
final readonly class ConfigRollout
{
    /** Seconds `compose up --wait` waits for healthchecks. */
    public const int WAIT_SECONDS = 300;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private ComposeRenderer $renderer,
        private ConfigVerifier $verifier,
    ) {}

    /**
     * Pulls the images the target stack needs and the engine lacks (the edge's Caddy image when switching to domain
     * mode), before anything changes: a failed pull is exit 3.
     *
     * @return array{pulled: list<string>}
     */
    public function pullMissing(InstanceState $after): array
    {
        $pulled = [];
        foreach ($this->images($this->render($after)) as $image) {
            if ($this->docker->inspectImage($image) instanceof ImageInfo) {
                continue;
            }

            try {
                $this->docker->pullImage($image);
            } catch (DockerCommandFailed $e) {
                throw new InstanceException("Cannot pull {$image}.", ExitCode::PreflightFailed, 'image_unavailable', 'Check the network and FSA_REGISTRY, then run the command again; nothing was changed.', true, ['image' => $image, 'stderr' => $e->outcome->stderrTail(3)], $e);
            }
            $pulled[] = $image;
        }

        return ['pulled' => $pulled];
    }

    /**
     * Services the change recreates, starts or removes, from the renders of both states.
     *
     * @return array{recreated: list<string>, added: list<string>, removed: list<string>}
     */
    public function affected(InstanceState $before, InstanceState $after): array
    {
        $old = $this->render($before);
        $new = $this->render($after);
        $recreated = array_values(array_filter(
            array_intersect($old->services(), $new->services()),
            static fn (string $service): bool => $old->service($service) !== $new->service($service) || $old->environments[$service] !== $new->environments[$service],
        ));

        return [
            'recreated' => $recreated,
            'added' => array_values(array_diff($new->services(), $old->services())),
            'removed' => array_values(array_diff($old->services(), $new->services())),
        ];
    }

    /**
     * Saves the target state (with a `config_changed` history entry), renders every file from it and brings the
     * stack to it. A failure leaves the files of the target in place: the caller reverts.
     *
     * @return array{recreated: list<string>, added: list<string>, removed: list<string>}
     */
    public function apply(ConfigChange $change): array
    {
        $affected = $this->affected($change->before, $change->after);
        if ($change->caRoot !== null) {
            AcmeCaRoot::write($this->directory, $change->caRoot);
        }
        $this->write($change->after->withHistoryEntry('config_changed', ['key' => $change->key, 'from' => $change->from, 'to' => $change->to]));
        $this->up($change->after);

        return $affected;
    }

    /**
     * Back to the previous state: its files, the ACME CA root it trusts (or none), and its services. Throws when the
     * previous stack does not come up either.
     *
     * @param  string|null  $previousCaRoot  the root file's content before the change (journaled in the snapshot)
     * @return array{restored: true}
     */
    public function revert(ConfigChange $change, ?string $previousCaRoot): array
    {
        $this->restoreCaRoot($change->before, $previousCaRoot);
        $this->write($change->before);
        $this->up($change->before);

        return ['restored' => true];
    }

    /** @return array{checked: list<string>} */
    public function verify(InstanceState $state): array
    {
        return $this->verifier->verify($state, $this->project($state));
    }

    /** The instance stopped as a whole (a revert that failed): nothing runs half-configured. Best effort. */
    public function stop(InstanceState $state): bool
    {
        try {
            $this->docker->composeStop($this->project($state));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The root file as the previous state expects it: gone when that state trusts none (an unused root must not stay
     * behind), else the journaled content when the file no longer has that state's SHA-256.
     */
    private function restoreCaRoot(InstanceState $before, ?string $previousCaRoot): void
    {
        $digest = InstanceSettings::of($before)->acmeCaRoot();
        if ($digest === null) {
            if ($this->directory->exists(AcmeCaRoot::FILE)) {
                $this->directory->files()->delete(AcmeCaRoot::FILE);
            }

            return;
        }
        if (! AcmeCaRoot::present($this->directory, $digest) && $previousCaRoot !== null && hash_equals($digest, hash('sha256', $previousCaRoot))) {
            AcmeCaRoot::write($this->directory, $previousCaRoot);
        }
    }

    private function write(InstanceState $state): void
    {
        $this->directory->saveState($state);
        $this->renderer->write($this->directory, $state, $this->secrets(), $this->emulated($state));
    }

    private function up(InstanceState $state): void
    {
        $project = $this->project($state);

        try {
            $this->docker->composeUp($project, [], self::WAIT_SECONDS, true);
        } catch (DockerCommandFailed $e) {
            $failing = $this->failing($project);
            throw new InstanceException(
                'Services did not become healthy: '.($failing === [] ? 'the stack' : implode(', ', $failing)).'.',
                ExitCode::HealthCheckFailed,
                'services_unhealthy',
                'Read `fsa logs <service>` for the cause.',
                true,
                ['services' => $failing, 'logs' => $this->logs($project, $failing), 'docker' => $e->outcome->stderrTail(5)],
                $e,
            );
        }
    }

    /**
     * Rendered as the current compose file was: under emulation (DOCKER_DEFAULT_PLATFORM, ADR 0070) every memory
     * limit is doubled, and a render without that would recreate every service.
     */
    private function emulated(InstanceState $state): bool
    {
        $current = ComposeFile::document($this->directory)['services']['app']['mem_limit'] ?? null;
        $native = $this->renderer->render($state, $this->secrets())->service('app')['mem_limit'] ?? null;

        return is_string($current) && is_string($native) && (int) $current === 2 * (int) $native;
    }

    private function render(InstanceState $state): RenderedProject
    {
        return $this->renderer->render($state, $this->secrets(), $this->emulated($state));
    }

    /** @return list<string> */
    private function images(RenderedProject $project): array
    {
        $images = array_map(static fn (string $service): mixed => $project->service($service)['image'] ?? null, $project->services());

        return array_values(array_unique(array_filter($images, is_string(...))));
    }

    private function secrets(): InstanceSecrets
    {
        return InstanceSecrets::fromEnvFiles(new EnvFiles($this->directory))
            ?? throw new InvalidState('The app env file lacks APP_KEY or the database password; they are never generated again silently.');
    }

    private function project(InstanceState $state): ComposeProject
    {
        return ComposeProject::forInstance($state, $this->directory);
    }

    /** @return list<string> */
    private function failing(ComposeProject $project): array
    {
        try {
            $containers = ComposeFile::serviceContainers($this->docker->composePs($project));
        } catch (Throwable) {
            return [];
        }
        $bad = array_filter($containers, static fn (ContainerInfo $c): bool => ! $c->running() || ($c->health !== null && $c->health !== 'healthy'));

        return array_values(array_unique(array_filter(array_map(static fn (ContainerInfo $c): ?string => $c->service(), $bad))));
    }

    /** @param  list<string>  $services */
    private function logs(ComposeProject $project, array $services): string
    {
        try {
            return $services === [] ? '' : $this->docker->composeLogs($project, $services, null, 30);
        } catch (Throwable) {
            return '';
        }
    }
}
