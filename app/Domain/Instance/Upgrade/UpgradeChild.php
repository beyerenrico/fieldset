<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The supervisor's side of the upgrade child (KTD19 step 6, KTD25, plan U18, ADR 0086). It writes the request into the
 * stage directory, starts the target image's `fieldset upgrade-child` detached (no socket, the instance network, the instance
 * directory read-only and only the stage writable, as the owner) and watches it: the child counts as alive while it runs
 * and its database session (`fieldset_upgrade_<id>`) was seen active within IDLE_SECONDS, judged by a socket-less one-off
 * that reads `pg_stat_activity`. A child that hangs or overruns MAX_SECONDS is removed, its backend terminated, and the
 * upgrade rolls back. The result is the stage's `result.json`; none means the child crashed.
 */
final readonly class UpgradeChild
{
    public const string ROLE = 'upgrade-child';

    public const int POLL_SECONDS = 5;

    public const int PROBE_SECONDS = 30;

    /** How long the child's session may show no activity (a hung migration, PHP stuck between statements). */
    public const int IDLE_SECONDS = 300;

    public const int MAX_SECONDS = 21600;

    public const int INSPECT_ATTEMPTS = 5;

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /** The stage directory of a journal (`operations/upgrade-<id>`), created 0700. */
    public function stage(string $id): string
    {
        return $this->directory->ensureDirectory(InstanceDirectory::OPERATIONS_DIR.'/upgrade-'.$id);
    }

    /**
     * @param  array<string, mixed>  $release  the journaled target (version, digests, from; for a rollback's render-only
     *                                         run also `settings` to render with and `migrate: false`)
     * @return array<string, mixed> the child's result: migrated, steps, staged
     *
     * @throws InstanceException exit 5 (the caller rolls back)
     */
    public function run(InstanceState $state, ComposeProject $project, array $release, string $id): array
    {
        $stage = $this->stage($id);
        $this->clear($stage);
        $secrets = InstanceSecrets::fromEnvFiles(new EnvFiles($this->directory));
        $emulated = $secrets instanceof InstanceSecrets && ComposeFile::emulated($this->directory, new ComposeRenderer, $state, $secrets);
        file_put_contents($stage.'/'.StageRenderer::REQUEST, json_encode(['journal' => $id, 'version' => $release['version'], 'from' => $release['from'] ?? null,
            'digests' => $release['digests'], 'emulated' => $emulated, 'settings' => $release['settings'] ?? [], 'migrate' => ($release['migrate'] ?? true) !== false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->removeLeftover($state);
        $container = $this->launch($state, $project, $release, $stage);
        try {
            $this->watch($project, $container, $id);
            $logs = $this->logs($container);
        } finally {
            $this->remove($container);
        }

        return $this->result($stage, $logs);
    }

    /** Removes a child container and terminates its database session (a rollback that found one running). */
    public function stop(InstanceState $state, ComposeProject $project, string $id): void
    {
        $this->removeLeftover($state);
        $this->database($project, 'terminate', $id);
    }

    /**
     * @param  array<string, mixed>  $release  `env`: a directory of env files mounted read-only over the instance's `env/`
     *                                         (a rollback's render in the previous release's layout), inside `operations/`
     */
    private function launch(InstanceState $state, ComposeProject $project, array $release, string $stage): string
    {
        $root = $this->directory->root;
        $env = is_string($release['env'] ?? null) && str_starts_with($release['env'], $root.'/'.InstanceDirectory::OPERATIONS_DIR.'/') && is_dir($release['env']) ? $release['env'] : null;
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: (string) ($release['digests']['app'] ?? ''), command: ['upgrade-child', '--dir', $root, '--json'],
            name: $state->project().'-'.self::ROLE, labels: Labels::forInstance($state->uuid(), self::ROLE),
            env: [StageRenderer::STAGE_ENV => $stage, 'HOME' => '/tmp'],
            volumes: [$root.':'.$root.':ro', ...($env === null ? [] : [$env.':'.$root.'/'.InstanceDirectory::ENV_DIR.':ro']), $stage.':'.$stage],
            network: $project->name.'_default', user: $state->containerUid().':'.($state->containerUid() === 0 ? 0 : $state->hostGid()),
            entrypoint: 'fieldset', detach: true, remove: false, restart: 'no',
        ), null, 300.0);
        if (! $outcome->successful() || trim($outcome->stdout) === '') {
            throw new InstanceException('The target image\'s upgrade child did not start: '.$outcome->stderrTail(3), ExitCode::RolledBack, 'child_failed', null, true, ['output' => $outcome->stderrTail(10)]);
        }

        return trim($outcome->stdout);
    }

    /**
     * Polls the child until it exits; removes it when it hangs or overruns. A probe that fails says nothing: it neither
     * keeps the child alive nor ages it (review A6).
     */
    private function watch(ComposeProject $project, string $container, string $id): void
    {
        $started = CarbonImmutable::now();
        $active = $started;
        $probed = $started->subSeconds(self::PROBE_SECONDS);
        while (($info = $this->inspect($container)) instanceof ContainerInfo && $info->running()) {
            $now = CarbonImmutable::now();
            if ($now->diffInSeconds($probed, true) >= self::PROBE_SECONDS) {
                $since = (int) $now->diffInSeconds(max($probed, $active), true);
                $probed = $now;
                $active = match ($this->sessionActive($project, $id)) {
                    true => $now,
                    null => $active->addSeconds($since), // unknown: the time since the last probe does not count
                    false => $active,
                };
            }
            $hung = $now->diffInSeconds($active, true) > self::IDLE_SECONDS;
            if ($hung || $now->diffInSeconds($started, true) > self::MAX_SECONDS) {
                $this->remove($container);
                $this->database($project, 'terminate', $id);
                throw new InstanceException($hung ? 'The upgrade child\'s database session showed no activity for '.self::IDLE_SECONDS.' s.' : 'The upgrade child did not finish within '.self::MAX_SECONDS.' s.',
                    ExitCode::RolledBack, $hung ? 'child_hung' : 'child_timeout', null, true, ['logs' => '']);
            }
            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }

    /** Whether the child's session was active at the probe; null when the probe itself failed. */
    private function sessionActive(ComposeProject $project, string $id): ?bool
    {
        try {
            $outcome = $this->database($project, 'activity', $id);
        } catch (Throwable) {
            return null;
        }
        $backends = $outcome->data['backends'] ?? null;
        if (! $outcome->ok || ! is_array($backends)) {
            return null;
        }

        return array_any($backends, static fn (mixed $backend): bool => is_array($backend) && ($backend['state'] ?? null) === 'active');
    }

    /** `docker inspect` of the child, a failing call retried (INSPECT_ATTEMPTS) before it counts: a live child is never removed for one. */
    private function inspect(string $container): ?ContainerInfo
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->docker->inspectContainer($container);
            } catch (Throwable $e) {
                if ($attempt >= self::INSPECT_ATTEMPTS) {
                    throw $e;
                }
                Sleep::for(self::POLL_SECONDS)->seconds();
            }
        }
    }

    /** `instance:upgrade-db <action>` for the child's session, in a socket-less one-off (KTD25). */
    private function database(ComposeProject $project, string $action, string $id): OneOffResult
    {
        return OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, [
            'php', 'artisan', 'instance:upgrade-db', $action, '--application-name='.ChildMigration::applicationName($id), '--json',
        ], timeout: 120.0));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InstanceException
     */
    private function result(string $stage, string $logs): array
    {
        $raw = @file_get_contents($stage.'/'.StageRenderer::RESULT);
        $result = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($result)) {
            throw new InstanceException('The upgrade child stopped without a result (it crashed).', ExitCode::RolledBack, 'child_crashed', null, true, ['logs' => $logs]);
        }
        if (($result['status'] ?? null) !== 'ok') {
            $error = is_array($result['error'] ?? null) ? $result['error'] : [];
            throw new InstanceException('The upgrade child failed: '.($error['message'] ?? 'no message'), ExitCode::RolledBack, is_string($error['code'] ?? null) ? $error['code'] : 'child_failed', null, true, ['logs' => $logs]);
        }

        return $result;
    }

    private function logs(string $container): string
    {
        try {
            return UpgradeLogs::redact($this->directory, $this->docker->containerLogs($container, 40));
        } catch (Throwable) {
            return '';
        }
    }

    private function remove(string $container): void
    {
        try {
            $this->docker->removeContainer($container);
        } catch (Throwable) {
            // already gone
        }
    }

    private function removeLeftover(InstanceState $state): void
    {
        foreach ($this->docker->containers(Labels::forInstance($state->uuid(), self::ROLE)) as $leftover) {
            $this->remove($leftover->id);
        }
    }

    /** The files an earlier attempt left in the stage (staged tree, result). */
    private function clear(string $stage): void
    {
        @unlink($stage.'/'.StageRenderer::RESULT);
        if (is_dir($stage.'/'.StageRenderer::FILES)) {
            UpgradeFiles::remove($stage.'/'.StageRenderer::FILES);
        }
    }
}
