<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use Throwable;

/**
 * The install phases that build the stack (R9, KTD6, KTD7): secrets, state and rendered files, pulls, and
 * `compose up --wait`. Each step is safe to repeat: a resumed install reuses the secrets already in the env
 * files (Postgres keeps its first password) and the identity already in the state.
 */
final readonly class InstanceStack
{
    /** Seconds `compose up --wait` waits for healthchecks. */
    public const int WAIT_SECONDS = 300;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private ComposeRenderer $renderer,
        private ProxySetup $proxy,
    ) {}

    /** @return array{generated: bool} */
    public function ensureSecrets(SecretRedactor $redactor): array
    {
        $env = new EnvFiles($this->directory);
        if (InstanceSecrets::fromEnvFiles($env) instanceof InstanceSecrets) {
            return ['generated' => false];
        }
        $app = $env->read('app');
        if (($app['APP_KEY'] ?? '') !== '' || ($app['DB_PASSWORD'] ?? '') !== '') {
            // Half a secret set: generating a new APP_KEY would make encrypted data unreadable, a new database
            // password would lock the app out of a database that keeps its first one.
            throw new InvalidState('env/app.env holds only one of APP_KEY and DB_PASSWORD; fsa never generates them again.');
        }
        $secrets = InstanceSecrets::generate();
        foreach ([$secrets->appKey, $secrets->dbPassword, $secrets->valkeyPassword, $secrets->catcherPassword] as $value) {
            $redactor->register($value);
        }
        // Enough for fromEnvFiles(); the state phase renders the full env files with the same values.
        $env->write('app', ['APP_KEY' => $secrets->appKey, 'DB_PASSWORD' => $secrets->dbPassword, 'REDIS_PASSWORD' => $secrets->valkeyPassword]);
        $env->write('mailpit', ['MP_UI_AUTH' => InstanceSecrets::CATCHER_USER.':'.$secrets->catcherPassword]);

        return ['generated' => true];
    }

    /**
     * @param  array<string, mixed>  $preflight  the doctor phase's data
     * @return array{project: string, services: list<string>}
     */
    public function writeState(array $preflight, HostFacts $facts): array
    {
        $uuid = (string) $preflight['uuid'];
        $state = $this->directory->hasState()
            ? $this->directory->state()
            : InstanceState::create($uuid, $this->directory->root, $facts->callerUid(), $facts->callerGid(), $facts->ownerUidInContainers());
        if ($state->uuid() !== $uuid) {
            throw new InvalidState('The instance state belongs to another installation than this install journal.');
        }
        /** @var array<string, string> $digests */
        $digests = $preflight['digests'];
        $state = $state->withRelease((string) $preflight['version'], $digests)
            ->withSettings(['port' => (int) $preflight['port'], 'mail_ui_port' => (int) $preflight['mail_ui_port']]);
        $state = ProxySetup::apply($state, $preflight);
        $this->directory->saveState($state);
        $rendered = $this->renderer->write($this->directory, $state, $this->secrets(), ($preflight['emulated'] ?? false) === true);
        $this->docker->composeConfig(ComposeProject::forInstance($state, $this->directory));

        return ['project' => $state->project(), 'services' => $rendered->services()];
    }

    /**
     * Proxy mode's `port` setup (ADR 0076): once Compose created the default network, its gateway is the address the
     * loopback port's connections arrive from, so it becomes the one trusted proxy; the env files are rendered again
     * before the app roles start.
     *
     * @param  array<string, mixed>  $preflight  the doctor phase's data
     * @return array{trusted_proxy?: string}
     */
    public function trustGateway(ComposeProject $project, array $preflight): array
    {
        $state = $this->directory->state();
        if (InstanceSettings::of($state)->proxy() !== 'port') {
            return [];
        }
        $gateway = $this->proxy->gateway($project);
        $state = $state->withSetting('trusted_proxies', $gateway);
        $this->directory->saveState($state);
        $this->renderer->write($this->directory, $state, $this->secrets(), ($preflight['emulated'] ?? false) === true);

        return ['trusted_proxy' => $gateway];
    }

    /** @return array{pulled: list<string>, present: list<string>} */
    public function pull(): array
    {
        $images = array_values(array_unique(array_filter(array_map(
            static fn (mixed $service): ?string => is_array($service) && is_string($service['image'] ?? null) ? $service['image'] : null,
            ComposeFile::document($this->directory)['services'] ?? [],
        ))));
        $pulled = [];
        $present = [];
        foreach ($images as $image) {
            if ($this->docker->inspectImage($image) instanceof ImageInfo) {
                $present[] = $image;

                continue;
            }
            $this->pullOne($image);
            $pulled[] = $image;
        }

        return ['pulled' => $pulled, 'present' => $present];
    }

    /**
     * @param  list<string>  $services  empty = all
     * @return array{started: list<string>}
     */
    public function up(ComposeProject $project, array $services): array
    {
        try {
            $this->docker->composeUp($project, $services, self::WAIT_SECONDS, $services === []);
        } catch (DockerCommandFailed $e) {
            $failing = $this->failing($project, $services);
            throw new InstanceException(
                'Services did not become healthy: '.implode(', ', $failing === [] ? ($services === [] ? ['the stack'] : $services) : $failing).'.',
                ExitCode::HealthCheckFailed,
                'services_unhealthy',
                'Read the log lines in the output or `fsa logs <service>`, fix the cause, then run `fsa install` again with the same flags: it resumes here.',
                true,
                ['services' => $failing, 'logs' => $this->logs($project, $failing), 'docker' => $e->outcome->stderrTail(5)],
                $e,
            );
        }

        return ['started' => $services === [] ? ComposeFile::services($this->directory) : $services];
    }

    public function secrets(): InstanceSecrets
    {
        return InstanceSecrets::fromEnvFiles(new EnvFiles($this->directory))
            ?? throw new InvalidState('The app env file lacks APP_KEY or the database password; they are never generated again silently.');
    }

    private function pullOne(string $image): void
    {
        try {
            $this->docker->pullImage($image);
        } catch (DockerCommandFailed $e) {
            throw new InstanceException("Cannot pull {$image}.", ExitCode::PreflightFailed, 'image_unavailable', 'Check the network and FSA_REGISTRY, then run `fsa install` again with the same flags: it resumes here.', true, ['image' => $image, 'stderr' => $e->outcome->stderrTail(3)], $e);
        }
    }

    /**
     * @param  list<string>  $services
     * @return list<string>
     */
    private function failing(ComposeProject $project, array $services): array
    {
        try {
            $containers = ComposeFile::serviceContainers($this->docker->composePs($project));
        } catch (Throwable) {
            return [];
        }
        $bad = array_filter($containers, static fn (ContainerInfo $c): bool => ! $c->running() || ($c->health !== null && $c->health !== 'healthy'));
        $names = array_values(array_unique(array_filter(array_map(static fn (ContainerInfo $c): ?string => $c->service(), $bad))));

        return $services === [] ? $names : array_values(array_intersect($names, $services));
    }

    /** @param  list<string>  $services */
    private function logs(ComposeProject $project, array $services): string
    {
        if ($services === []) {
            return '';
        }

        try {
            return $this->docker->composeLogs($project, $services, null, 30);
        } catch (Throwable) {
            return '';
        }
    }
}
