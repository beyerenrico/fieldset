<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\RenderedProject;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Install\SmokeTest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use Closure;
use Throwable;

/**
 * Applies and reverts a settings change on disk and in Docker (KTD6, ADR 0077, ADR 0078). The state is the single
 * authority: apply saves the target state and renders runtime, env files, Caddyfile and compose file from it; revert
 * saves the previous state and renders again. Both bring the stack to it with `compose up --wait --remove-orphans`:
 * Compose recreates exactly the services whose definition or env-file content changed (verified: an env-file change
 * recreates that service only) and removes services the mode no longer has (the edge after domain → local), while
 * unchanged services keep running. `affected()` reports the same set from the two renders.
 *
 * Writers stop before the risky step (KTD19): apply first stops queue and scheduler (their stop grace period lets
 * running jobs finish) and starts everything else, so no mail, notification or signed link goes out with an URL
 * that is not verified yet; `startWorkers()` brings them back on the new settings once the verification passed. A
 * revert brings the whole previous stack back, workers included.
 *
 * SMTP credentials (`config set mail`, ADR 0080) live only in the env files: apply renders the new ones and first keeps
 * the old ones in `env/smtp-previous` (0600, beside the env files, never journaled), so a revert, also one a resumed
 * run finishes, renders the previous settings with their own credentials; the file goes once the change is settled.
 */
final readonly class ConfigRollout
{
    /** Seconds `compose up --wait` waits for healthchecks. */
    public const int WAIT_SECONDS = 300;

    /** The writers: stopped while a change is applied and verified (KTD19), started again afterwards. */
    public const array WORKERS = [AppRoles::QUEUE, AppRoles::SCHEDULER];

    /** What serves requests or sends mail: stopped when a revert fails too; Postgres and Valkey keep running. */
    public const array FRONTENDS = [AppRoles::APP, AppRoles::QUEUE, AppRoles::SCHEDULER, EdgeService::NAME, MailpitService::NAME];

    /** Seconds apply may take: stopping the workers (their grace period) and `up --wait`. */
    public const int APPLY_SECONDS = AppRoles::STOP_GRACE_SECONDS + self::WAIT_SECONDS;

    /** The previous SMTP credentials while a `config set mail` is applied (not `*.env`: no service reads it). */
    public const string CREDENTIALS_STASH = InstanceDirectory::ENV_DIR.'/smtp-previous';

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
    public function affected(InstanceState $before, InstanceState $after, ?SmtpCredentials $credentials = null): array
    {
        $old = $this->render($before);
        $new = $this->render($after, $credentials);
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
     * Stops the workers, saves the target state (with a `config_changed` history entry), renders every file from it
     * and brings every other service to it. A failure leaves the files of the target in place: the caller reverts.
     *
     * @return array{recreated: list<string>, added: list<string>, removed: list<string>}
     */
    public function apply(ConfigChange $change): array
    {
        $affected = $this->affected($change->before, $change->after, $change->credentials);
        $this->docker->composeStop($this->project($change->before), self::WORKERS);
        if ($change->caRoot !== null) {
            AcmeCaRoot::write($this->directory, $change->caRoot);
        }
        $secrets = $this->secrets();
        if ($change->credentials instanceof SmtpCredentials) {
            $this->stashCredentials($secrets);
            $secrets = $secrets->withSmtpCredentials($change->credentials->username, $change->credentials->password);
        }
        $this->write($change->after->withHistoryEntry('config_changed', ['key' => $change->key, 'from' => $change->from, 'to' => $change->to]), $secrets);
        $this->up($change->after, array_values(array_diff(ComposeFile::services($this->directory), self::WORKERS)));

        return $affected;
    }

    /**
     * Queue and scheduler on the verified settings (the whole project `up`, so anything else stopped starts too).
     *
     * @return array{started: list<string>}
     */
    public function startWorkers(InstanceState $state): array
    {
        $this->up($state);

        return ['started' => self::WORKERS];
    }

    /**
     * Back to the previous state: its files, the ACME CA root it trusts (or none), and its whole stack, workers
     * included. Throws when the previous stack does not come up either; `$completed` hears each step that finished
     * (`files`: state.json and the rendered files are the previous ones again; `services`: they are up).
     *
     * @param  string|null  $previousCaRoot  the root file's content before the change (journaled in the snapshot)
     * @param  (Closure(string): void)|null  $completed
     * @return array{restored: true}
     */
    public function revert(ConfigChange $change, ?string $previousCaRoot, ?Closure $completed = null): array
    {
        $this->restoreCaRoot($change->before, $previousCaRoot);
        $this->write($change->before, $this->previousSecrets());
        $completed?->__invoke('files');
        $this->up($change->before);
        $this->discardStash();
        $completed?->__invoke('services');

        return ['restored' => true];
    }

    /** The change is settled (verified and running, or reverted): the previous SMTP credentials are not needed any more. */
    public function discardStash(): void
    {
        if ($this->directory->exists(self::CREDENTIALS_STASH)) {
            $this->directory->files()->delete(self::CREDENTIALS_STASH);
        }
    }

    /** Keeps the credentials in effect before the first apply; a resumed apply finds them already kept. */
    private function stashCredentials(InstanceSecrets $current): void
    {
        if (! $this->directory->exists(self::CREDENTIALS_STASH)) {
            $this->directory->write(self::CREDENTIALS_STASH, EnvFiles::encode(['MAIL_USERNAME' => (string) $current->smtpUsername, 'MAIL_PASSWORD' => (string) $current->smtpPassword]), 0600);
        }
    }

    /** The secrets to render the previous state with: its SMTP credentials when a mail change replaced them. */
    private function previousSecrets(): InstanceSecrets
    {
        $secrets = $this->secrets();
        $stash = $this->directory->read(self::CREDENTIALS_STASH);
        if ($stash === null) {
            return $secrets;
        }
        $values = EnvFiles::decode($stash);
        $value = static fn (string $key): ?string => ($values[$key] ?? '') === '' ? null : $values[$key];

        return $secrets->withSmtpCredentials($value('MAIL_USERNAME'), $value('MAIL_PASSWORD'));
    }

    /** @return array{checked: list<string>} */
    public function verify(InstanceState $state, ?ProgressReporter $progress = null, string $phase = 'verify'): array
    {
        return $this->verifier->verify($state, $this->project($state), $progress, $phase);
    }

    /** The longest verify() can take for this state (smoke test, edge certificate, URL check). */
    public static function verifySeconds(InstanceState $state): int
    {
        return SmokeTest::maxSeconds($state) + ConfigVerifier::HOST_CHECK_SECONDS;
    }

    /**
     * After a failed revert: everything that serves requests or sends mail stops, so nothing runs half-configured;
     * Postgres and Valkey keep running (their data is not at stake). Best effort.
     *
     * @return list<string>|null the services stopped, null when stopping failed
     */
    public function stopFrontends(InstanceState $state): ?array
    {
        $services = array_values(array_intersect(self::FRONTENDS, ComposeFile::services($this->directory)));

        try {
            $this->docker->composeStop($this->project($state), $services);

            return $services;
        } catch (Throwable) {
            return null;
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

    private function write(InstanceState $state, ?InstanceSecrets $secrets = null): void
    {
        $this->directory->saveState($state);
        $this->renderer->write($this->directory, $state, $secrets ?? $this->secrets(), $this->emulated($state));
    }

    /** @param  list<string>  $services  the whole project when empty */
    private function up(InstanceState $state, array $services = []): void
    {
        $project = $this->project($state);

        try {
            $this->docker->composeUp($project, $services, self::WAIT_SECONDS, true);
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

    private function render(InstanceState $state, ?SmtpCredentials $credentials = null): RenderedProject
    {
        $secrets = $this->secrets();
        if ($credentials instanceof SmtpCredentials) {
            $secrets = $secrets->withSmtpCredentials($credentials->username, $credentials->password);
        }

        return $this->renderer->render($state, $secrets, $this->emulated($state));
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
