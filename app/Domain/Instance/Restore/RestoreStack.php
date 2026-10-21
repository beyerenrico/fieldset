<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Compose\Services\ValkeyService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Install\SmokeTest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\State\RuntimeWriter;
use SensitiveParameter;
use Throwable;

/**
 * The stack around a restore (plan U17, KTD19, ADR 0085). Quiesce like an upgrade: the gate goes up (an app behind it
 * answers 503 to everything but its own healthcheck and the operation's smoke token), app, scheduler and the backup
 * service stop (until the verification starts the app again nothing answers: a loopback port refuses connections, the
 * edge or a proxy answers 502; ADR 0085 M12 amendment), then the queue with its grace period (Horizon finishes running
 * jobs), and a one-off seals pending audit entries so the heads the ledger records are final. After the swap the state is
 * rendered again (the fresh storage volume, the APP keys), Valkey is flushed (cache and queued jobs of the replaced data),
 * the app starts behind the gate and the internal smoke test passes with the token; only then does the gate go down and
 * everything else start. "Everything" never includes the quick tunnel (M12 gate C5, as an upgrade's rollback): a running
 * one keeps running, an ended one is left to the next mutating command's sweep instead of coming back with a new URL the
 * state does not know.
 */
final readonly class RestoreStack
{
    /** Writers stopped first; the queue follows with its grace period. */
    public const array WRITERS = [AppRoles::APP, AppRoles::SCHEDULER, BackupService::NAME];

    public const int WAIT_SECONDS = 300;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private ComposeRenderer $renderer,
        private SmokeTest $smoke,
    ) {}

    /** @return string the smoke token of the raised gate */
    public function quiesce(InstanceState $state, ComposeProject $project): string
    {
        $token = new RuntimeWriter($this->directory)->raiseGate($state, 'restore');
        $running = array_map(static fn (ContainerInfo $container): ?string => $container->service(), array_filter(ComposeFile::serviceContainers($this->docker->composePs($project)), static fn (ContainerInfo $c): bool => $c->running()));
        $writers = array_values(array_intersect(self::WRITERS, $running));
        if ($writers !== []) {
            $this->docker->composeStop($project, $writers);
        }
        if (in_array(AppRoles::QUEUE, $running, true)) {
            $this->docker->composeStop($project, [AppRoles::QUEUE], AppRoles::STOP_GRACE_SECONDS);
        }
        $this->artisan($project, ['audit:seal'], 'audit_seal_failed');

        return $token;
    }

    /** Raises the gate again (a resumed restore) and returns the new smoke token. */
    public function raise(InstanceState $state): string
    {
        return new RuntimeWriter($this->directory)->raiseGate($state, 'restore');
    }

    /**
     * The final heads of the live database (`audit:verify --heads`): reported even when a chain is broken.
     *
     * @return array{epoch: int, heads: array<string, mixed>, intact: bool}
     */
    public function heads(ComposeProject $project): array
    {
        $outcome = $this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'audit:verify', '--heads'], timeout: 1800.0);
        foreach (array_reverse(explode("\n", $outcome->stdout)) as $line) {
            $decoded = str_starts_with(trim($line), '{') ? json_decode(trim($line), true) : null;
            if (is_array($decoded) && array_key_exists('heads', $decoded)) {
                return ['epoch' => is_int($decoded['epoch'] ?? null) ? $decoded['epoch'] : 1, 'heads' => is_array($decoded['heads']) ? $decoded['heads'] : [], 'intact' => $outcome->successful()];
            }
        }

        throw new InstanceException('The audit chain heads could not be read.', ExitCode::HealthCheckFailed, 'audit_heads_failed', 'Read `fieldset logs postgres`; nothing was changed yet.', true, ['output' => $outcome->stderrTail(5)]);
    }

    /** Saves the state and renders everything from it again (compose file, env files, runtime). */
    public function apply(InstanceState $state, #[SensitiveParameter] ?InstanceSecrets $secrets = null): void
    {
        $secrets ??= $this->secrets();
        $this->directory->saveState($state);
        $this->renderer->write($this->directory, $state, $secrets, ComposeFile::emulated($this->directory, $this->renderer, $state, $secrets));
    }

    /** Cache and queues of the replaced data go (R35); a failure is reported, not fatal. */
    public function flushValkey(ComposeProject $project): bool
    {
        // The image's entrypoint appends VALKEY_EXTRA_FLAGS (`--requirepass …`) to every command: emptied here, or valkey-cli
        // reads it as arguments of FLUSHALL (a syntax error, found by J08) and the password lands in its argv.
        $outcome = $this->docker->composeRun($project, ValkeyService::NAME, ['valkey-cli', '-h', ValkeyService::NAME, 'FLUSHALL'], ['VALKEY_EXTRA_FLAGS' => ''], timeout: 120.0);

        return $outcome->successful() && str_contains($outcome->stdout, 'OK'); // valkey-cli exits 0 on NOAUTH (LEARNINGS 82)
    }

    /** @return list<string> what the smoke test checked behind the gate */
    public function startAndVerify(InstanceState $state, ComposeProject $project, #[SensitiveParameter] string $token): array
    {
        $this->up($project, [AppRoles::APP]);

        return $this->smoke->internal($state, $project, $token);
    }

    /** Starts every service behind the gate, then lowers it (review A4: no request lands before everything is up). */
    public function reopen(InstanceState $state, ComposeProject $project): void
    {
        $this->startAll($project);
        $this->lowerGate($state);
    }

    /**
     * The previous instance again, its database unchanged (third review L7): the app must become healthy, then the gate
     * goes down and the other services start. One that does not is reported, not fatal: requests reach the data they
     * always reached.
     *
     * @return list<string> the services that did not start
     *
     * @throws InstanceException exit 4 when the app itself does not become healthy
     */
    public function reopenPrevious(InstanceState $state, ComposeProject $project): array
    {
        $this->up($project, [AppRoles::APP]);
        $this->lowerGate($state);
        try {
            $this->up($project, $this->services());
        } catch (Throwable) {
            $running = array_map(static fn (ContainerInfo $container): ?string => $container->service(), array_filter(ComposeFile::serviceContainers($this->docker->composePs($project)), static fn (ContainerInfo $c): bool => $c->running()));
            $missing = array_values(array_diff([AppRoles::QUEUE, AppRoles::SCHEDULER, BackupService::NAME], $running));

            return $missing === [] ? ['queue, scheduler or backup (running, not healthy)'] : $missing;
        }

        return [];
    }

    /** Every service (workers, scheduler, backup) up and healthy, the quick tunnel excepted (C5); the gate stays as it is. */
    public function startAll(ComposeProject $project): void
    {
        $this->up($project, $this->services());
    }

    /** Whether app, queue, scheduler or the backup service runs: a resumed operation then takes its heads and backup again (D7). */
    public function writersRunning(ComposeProject $project): bool
    {
        return array_any(ComposeFile::serviceContainers($this->docker->composePs($project)), static fn (ContainerInfo $container): bool => $container->running() && in_array($container->service(), RestoreHalt::WRITERS, true));
    }

    /** @return list<string> every service (an empty list) or, when the compose file has the quick tunnel, every other one */
    private function services(): array
    {
        $services = ComposeFile::services($this->directory);

        return in_array(TunnelService::NAME, $services, true) ? array_values(array_diff($services, [TunnelService::NAME])) : [];
    }

    public function lowerGate(InstanceState $state): void
    {
        new RuntimeWriter($this->directory)->lowerGate($state);
    }

    public function secrets(): InstanceSecrets
    {
        return InstanceSecrets::fromEnvFiles(new EnvFiles($this->directory))
            ?? throw new InvalidState('The app env file lacks APP_KEY or the database password; they are never generated again silently.');
    }

    /** @param  list<string>  $services */
    private function up(ComposeProject $project, array $services): void
    {
        try {
            $this->docker->composeUp($project, $services, self::WAIT_SECONDS);
        } catch (DockerCommandFailed $e) {
            throw new InstanceException('Services did not become healthy after the restore.', ExitCode::HealthCheckFailed, 'services_unhealthy', 'Read `fieldset logs app`.', true, ['docker' => $e->outcome->stderrTail(5)], $e);
        }
    }

    /** @param  list<string>  $command */
    private function artisan(ComposeProject $project, array $command, string $code): void
    {
        $outcome = $this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', ...$command], timeout: 600.0);
        if (! $outcome->successful()) {
            throw new InstanceException('`'.implode(' ', $command).'` failed.', ExitCode::HealthCheckFailed, $code, 'Read the output; the live database was not changed.', true, ['output' => $outcome->stderrTail(5)]);
        }
    }
}
