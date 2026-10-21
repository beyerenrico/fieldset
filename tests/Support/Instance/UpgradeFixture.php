<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Release\ReleaseManifest;
use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Instance\Upgrade\StageRenderer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Release\ReleaseFixtures;

/**
 * An installed instance (RestoreFixture: scripted Docker, an in-memory database list, the real prepare step) whose Docker
 * and release server answer the steps of `fieldset upgrade` (plan U18): a release server (Http fake) serving signed
 * manifests by version and `latest`, the upgrade child (the real StageRenderer renders the staged files in-process, the
 * result file says ok or failed; `crash` leaves none, `hang` keeps the container running), the liveness and index one-offs,
 * the queue drain, and safety backups written to disk as real sets so that their verification and a rollback's restore
 * read them. Time runs with the faked sleeps (Sleep synced with Carbon).
 */
final class UpgradeFixture
{
    public const string TARGET = '2.0.0-alpha.2';

    public const string TARGET_DIGEST = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const string RELEASE_URL = 'https://releases.test/fieldset';

    public const string CHILD_ID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public RestoreFixture $restore;

    public InstanceCommandFixture $instance;

    /** @var array<string, string> version => manifest JSON the release server serves */
    public array $releases = [];

    /** An HTTP status every request to the release server gets (null: it serves the releases). */
    public ?int $releaseStatus = null;

    /** The version `latest/download` serves (null: 404, as before the first stable release). */
    public ?string $latest = null;

    /** The child: `ok`, `fail` (result failed), `crash` (no result file), `hang` (keeps running). */
    public string $child = 'ok';

    /** Inspections of the child that report it running before it exits (`ok`/`fail`). */
    public int $childRunningPolls = 0;

    /** Whether the liveness one-off sees the child's backend active. */
    public bool $childActive = true;

    /** @var list<array{pending: int, reserved: int, delayed: int}> queue sizes the drain one-off reports, in order */
    public array $drain = [];

    /** @var list<string> invalid indexes the index check reports */
    public array $invalidIndexes = [];

    /** Post-processes the staged files after the real render (a target that adds a service or an env key). */
    public ?Closure $stage = null;

    /** compose up of the app fails once the target's files are live (the target's health check fails). */
    public bool $appUnhealthy = false;

    /** compose up fails for any service list containing this one, while the target's files are live. */
    public ?string $unhealthyService = null;

    /** The safety backup's one-off fails (null: it writes a real set). */
    public ?ProcessOutcome $safetyOutcome = null;

    /** Problems the safety backup's set has on disk (a damaged checksum: verification fails). */
    public bool $damagedSafety = false;

    /** @var list<string> the queue one-offs in order: sizes, export, drop */
    public array $queueActions = [];

    /** @var list<list<array<string, mixed>>> client sessions the in-flight check sees, poll by poll (the last one stays) */
    public array $sessions = [];

    /** @var array{database_bytes: int, storage_bytes: int} what the size one-off reports for the live data */
    public array $liveSize = ['database_bytes' => 10_485_760, 'storage_bytes' => 1_048_576];

    /** Whether the child's liveness probe one-off fails (Docker or the database hiccups). */
    public bool $probeFails = false;

    /** The security state export's one-off (null: a small valid document). */
    public ?ProcessOutcome $exportOutcome = null;

    /** @var list<string> safety backups written, in order */
    public array $safetyBackups = [];

    /** @var list<array<string, mixed>> requests the child got (request.json) */
    public array $childRequests = [];

    private int $polls = 0;

    /** @param  array<string, mixed>  $installFlags  the install's flags (a domain-mode instance) */
    public function __construct(array $installFlags = [])
    {
        $this->restore = new RestoreFixture($installFlags);
        $this->restore->freeBytes = 50 * 1024 ** 3;
        $this->instance = $this->restore->instance;
        $this->instance->facts(['lanAddresses' => [ConfigFixture::HOST_ADDRESS], 'releaseUrl' => self::RELEASE_URL]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20T10:00:00Z'));
        Sleep::fake(syncWithCarbon: true);
        $this->serve(self::TARGET);
        Http::fake(fn (Request $request) => $this->answerHttp($request));
        $docker = $this->instance->docker;
        $run = $docker->runResponder;
        $docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $this->answerRun($spec) ?? ($run instanceof Closure ? $run($spec) : null));
        $oneOff = $docker->composeRunResponder;
        $docker->respondToComposeRun(fn (array $command, ?string $input = null): ?ProcessOutcome => $this->answerOneOff($command) ?? ($oneOff instanceof Closure ? $oneOff($command, $input) : null));
        $docker->onCall['inspectContainer'] = fn (array $args) => $this->childPoll((string) $args['id']);
        $docker->onCall['composeUp'] = fn (array $args) => $this->failUp($args['services']);
    }

    /** Serves a release (its manifest pins the app at `$digest`; `$upgradesFrom` overrides the oldest source). */
    public function serve(string $version, string $digest = self::TARGET_DIGEST, ?string $upgradesFrom = null): void
    {
        $manifest = ReleaseFixtures::manifest($upgradesFrom === null ? $version : InstanceCommandFixture::VERSION, $digest);
        if ($upgradesFrom !== null) {
            $manifest = new ReleaseManifest(ReleaseVersion::parse($version), ReleaseVersion::parse($upgradesFrom), $manifest->notesUrl, $manifest->platforms, $manifest->images, $manifest->assets);
        }
        $this->releases[$version] = $manifest->toJson();
    }

    /** `fieldset upgrade --supervise` (the detached supervisor's run, in-process) with --json. */
    public function upgrade(array $options = []): array
    {
        return $this->instance->run('fieldset:upgrade', ['--supervise' => true, '--to' => self::TARGET, ...$options]);
    }

    public static function targetImage(): string
    {
        return InstanceCommandFixture::MIRROR.'/beyerenrico/fieldset@'.self::TARGET_DIGEST;
    }

    /** @return list<string> the services of each compose up since the mark, in order */
    public function ups(int $mark): array
    {
        return array_map(static fn (array $call): string => implode(',', $call['services']), $this->restore->config->callsSince($mark, 'composeUp'));
    }

    /** @return list<string> the services of each compose stop since the mark */
    public function stops(int $mark): array
    {
        return array_map(static fn (array $call): string => implode(',', $call['services']), $this->restore->config->callsSince($mark, 'composeStop'));
    }

    /** @return list<array<string, mixed>> one-off calls of an artisan command (`instance:upgrade-db` …) */
    public function oneOffs(string $name): array
    {
        return $this->instance->oneOffCalls($name);
    }

    public function compose(): string
    {
        return (string) $this->instance->directory->read('compose.yaml');
    }

    public function gate(): array
    {
        return json_decode((string) $this->instance->directory->read('runtime/runtime.json'), true)['gate'];
    }

    private function answerHttp(Request $request): mixed
    {
        if ($this->releaseStatus !== null) {
            return Http::response('release server says no', $this->releaseStatus);
        }
        $url = $request->url();
        $latest = self::RELEASE_URL.'/latest/download/';
        $version = str_starts_with($url, $latest) ? $this->latest : (preg_match('#/download/v([^/]+)/#', $url, $match) === 1 ? $match[1] : null);
        $json = $version === null ? null : ($this->releases[$version] ?? null);
        if ($json === null) {
            return Http::response('Not Found', 404);
        }

        return match (basename($url)) {
            ManifestVerifier::MANIFEST => Http::response($json),
            ManifestVerifier::BUNDLE => Http::response('{"mediaType":"application/vnd.dev.sigstore.bundle.v0.3+json"}'),
            default => Http::response('Not Found', 404),
        };
    }

    private function answerRun(ContainerSpec $spec): ?ProcessOutcome
    {
        if ($spec->entrypoint !== 'fieldset' || ($spec->command[0] ?? null) !== 'upgrade-child') {
            return null;
        }
        $stage = $spec->env[StageRenderer::STAGE_ENV] ?? '';
        $request = json_decode((string) @file_get_contents($stage.'/'.StageRenderer::REQUEST), true) ?: [];
        $this->childRequests[] = $request;
        $this->polls = $this->childRunningPolls;
        if (in_array($this->child, ['ok', 'fail'], true)) {
            $this->finishChild($stage);
        }
        $state = $this->child === 'hang' || $this->polls > 0 ? 'running' : 'exited';
        $this->instance->docker->containers[self::CHILD_ID] = new ContainerInfo(self::CHILD_ID, (string) $spec->name, $state, '2026-10-20T10:00:00Z', null, $spec->image, $spec->labels);

        return new ProcessOutcome(0, self::CHILD_ID."\n");
    }

    /** What the child leaves in its stage: the staged files (real render) and its result. */
    private function finishChild(string $stage): void
    {
        $staged = app(StageRenderer::class)->render($stage);
        if ($this->stage instanceof Closure) {
            ($this->stage)($stage.'/'.StageRenderer::FILES);
        }
        $result = $this->child === 'ok'
            ? ['status' => 'ok', 'migrated' => ['2026_10_20_000000_fieldset_test_upgrade'], 'steps' => [], 'staged' => $staged, 'invalid_indexes' => []]
            : ['status' => 'failed', 'error' => ['code' => 'migration_failed', 'message' => 'SQLSTATE[55P03]: lock timeout'], 'staged' => $staged];
        file_put_contents($stage.'/'.StageRenderer::RESULT, json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    private function childPoll(string $id): void
    {
        if ($id !== self::CHILD_ID || ! isset($this->instance->docker->containers[$id]) || $this->child === 'hang') {
            return;
        }
        if ($this->polls-- <= 0) {
            $this->instance->docker->stopContainer($id);
        }
    }

    /** @param  list<string>  $services */
    private function failUp(array $services): void
    {
        $live = str_contains($this->compose(), self::TARGET_DIGEST);
        if ($live && $this->appUnhealthy && in_array('app', $services, true)) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'dependency failed to start: container fieldset-app-1 is unhealthy'));
        }
        if ($live && $this->unhealthyService !== null && in_array($this->unhealthyService, $services, true)) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', "container {$this->unhealthyService} is unhealthy"));
        }
    }

    /** @param  list<string>  $command */
    private function answerOneOff(array $command): ?ProcessOutcome
    {
        if (($command[0] ?? null) === 'fieldset' && ($command[1] ?? null) === 'backup-daemon' && str_starts_with((string) ($command[3] ?? ''), '--kind=safety-')) {
            return $this->safetyOutcome ?? $this->safetyBackup(substr((string) $command[3], 7));
        }

        return match ($command[2] ?? null) {
            'instance:upgrade-db' => $this->database($command[3] ?? ''),
            'instance:queue-drain' => $this->queue(in_array('--export', $command, true) ? 'export' : (in_array('--drop', $command, true) ? 'drop' : 'sizes')),
            'instance:security-export' => $this->exportOutcome ?? InstanceCommandFixture::oneOffJson(['command' => 'instance:security-export', 'status' => 'ok',
                'data' => ['format' => 'fieldset-security-state', 'version' => 1, 'generated_at' => '2026-10-20T10:00:00Z', 'since' => null, 'tables' => ['users' => ['columns' => ['id'], 'rows' => [[1]]]], 'sequences' => []]]),
            'instance:rollback-loss' => InstanceCommandFixture::oneOffJson(['command' => 'instance:rollback-loss', 'status' => 'ok',
                'data' => ['since' => '2026-10-20T10:00:00Z', 'counts' => ['submissions' => 12, 'activity_log' => 340, 'users' => 0]]]),
            default => null,
        };
    }

    private function safetyBackup(string $kind): ProcessOutcome
    {
        $name = $kind.'-20261020T10'.sprintf('%04d', count($this->safetyBackups)).'Z';
        $this->safetyBackups[] = $name;
        $dir = $this->instance->directory->root.'/backups/'.$name;
        BackupSetFixture::fieldset($dir, ['kind' => $kind, ...$this->restore->manifest()], appKey: $this->restore->appKey());
        if ($this->damagedSafety) {
            file_put_contents($dir.'/settings.json', '{"changed":true}');
        }

        return InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'ok', 'changed' => true, 'data' => [
            'kind' => $kind, 'status' => 'succeeded', 'backup' => $name, 'bytes' => 52_431,
            'result' => ['backup' => $name, 'kind' => $kind, 'created_at' => '2026-10-20T10:00:00Z', 'bytes' => 52_431],
        ]]);
    }

    private function database(string $action): ProcessOutcome
    {
        if ($action === 'activity' && $this->probeFails) {
            return new ProcessOutcome(1, '', 'Error response from daemon: probe failed');
        }
        $data = match ($action) {
            'activity' => ['backends' => $this->childActive ? [['pid' => 4242, 'state' => 'active', 'wait_event_type' => null]] : []],
            'foreign' => ['sessions' => $this->sessions === [] ? [] : (count($this->sessions) > 1 ? array_shift($this->sessions) : $this->sessions[0])],
            'size' => $this->liveSize,
            'terminate' => ['terminated' => 1],
            'invalid-indexes' => ['indexes' => $this->invalidIndexes],
            default => [],
        };

        return InstanceCommandFixture::oneOffJson(['command' => 'instance:upgrade-db', 'status' => 'ok', 'data' => $data]);
    }

    private function queue(string $action): ProcessOutcome
    {
        $sizes = $this->drain === [] ? ['pending' => 0, 'reserved' => 0, 'delayed' => 0] : (count($this->drain) > 1 ? array_shift($this->drain) : $this->drain[0]);
        $this->queueActions[] = $action;
        $data = match ($action) {
            'export' => [...$sizes, 'payloads' => array_fill(0, $sizes['pending'] + $sizes['reserved'], ['queue' => 'default', 'state' => 'pending', 'payload' => '{"job":"x"}'])],
            'drop' => [...$sizes, 'dropped' => $sizes['pending'] + $sizes['reserved']],
            default => $sizes,
        };

        return InstanceCommandFixture::oneOffJson(['command' => 'instance:queue-drain', 'status' => 'ok', 'data' => $data]);
    }

    /**
     * Leaves an open upgrade journal as a supervisor killed in `$phase` would (started, or completed): every phase before
     * it completed with the data the supervisor records, the gate raised, a real safety backup on disk once that phase
     * ran, the target's files live from `start` on and the target committed in the state after `commit`.
     */
    public function interrupted(string $phase, bool $completed = false): void
    {
        $state = $this->instance->state();
        $digests = [...$state->digests(), 'app' => self::targetImage()];
        $data = [
            'release' => ['version' => self::TARGET, 'from' => $state->version(), 'current' => false, 'digests' => $digests, 'previous_digests' => $state->digests(),
                'previous_compose' => $this->compose(), 'platforms' => ['linux/amd64', 'linux/arm64'], 'notes_url' => 'https://example.com/notes', 'upgrades_from' => '2.0.0-alpha.1'],
            'doctor' => ['checks' => [], 'space' => ['required' => 0, 'free' => null]], 'pull' => ['pulled' => []],
            'quiesce' => ['gate' => 'raised', 'drain' => [], 'dropped_jobs' => null],
            'safety_backup' => fn (): array => ['backup' => $name = (string) json_decode($this->safetyBackup('safety-upgrade')->stdout, true)['data']['backup'], 'path' => $this->instance->directory->root.'/backups/'.$name, 'created_at' => null, 'prepared' => []],
            'migrate' => ['migrated' => [], 'steps' => [], 'staged' => []], 'start' => ['services' => ['app']], 'smoke' => ['checked' => []], 'commit' => ['version' => self::TARGET],
        ];
        $journal = new OperationJournal($this->instance->directory);
        $journal->begin('upgrade', posix_getuid(), ['to' => self::TARGET, 'major' => false, 'drop_jobs' => false]);
        foreach (array_keys($data) as $name) {
            $journal->startPhase($name, [], ! in_array($name, ['release', 'doctor', 'pull'], true));
            $this->interruptedEffects($name, $state, $digests);
            if ($name === $phase && ! $completed) {
                return;
            }
            $journal->completePhase($name, $data[$name] instanceof Closure ? ($data[$name])() : $data[$name]);
            if ($name === $phase) {
                return;
            }
        }
    }

    /** @param  array<string, string>  $digests */
    private function interruptedEffects(string $phase, InstanceState $state, array $digests): void
    {
        $target = $state->withRelease(self::TARGET, $digests);
        match ($phase) {
            'quiesce' => new RuntimeWriter($this->instance->directory)->raiseGate($state, 'upgrade'),
            'start' => new ComposeRenderer()->write($this->instance->directory, $target, app(RestoreStack::class)->secrets()),
            'commit' => $this->instance->directory->saveState($target->withPreviousRelease((string) $state->version(), $state->digests(), $this->compose())),
            default => null,
        };
    }

    /**
     * Turns the newest archived journal of `$operation` back into the open one, as if its run had been killed in `$phase`
     * (its last attempt started, or completed with `$completed`): later phases and the outcome are dropped; journal notes
     * stay unless `$dropEvents`.
     */
    public function reopen(string $operation, string $phase, bool $completed = false, bool $dropEvents = false): void
    {
        $journal = new OperationJournal($this->instance->directory);
        $record = array_values(array_filter($journal->history(), static fn (JournalRecord $record): bool => $record->operation() === $operation))[0];
        $document = $record->toArray();
        $phases = $document['phases'];
        $index = max(array_keys(array_filter($phases, static fn (array $entry): bool => $entry['name'] === $phase)));
        $last = $completed ? $phases[$index] : [...$phases[$index], 'status' => 'started', 'finished_at' => null];
        $document = [...$document, 'phases' => [...array_slice($phases, 0, $index), $last], 'outcome' => null, 'finished_at' => null, 'result' => [],
            'events' => $dropEvents ? [] : $document['events']];
        foreach ($this->instance->directory->files()->list(OperationJournal::HISTORY_DIR) as $name) {
            if (str_contains($name, $record->id())) {
                $this->instance->directory->files()->delete(OperationJournal::HISTORY_DIR.'/'.$name);
            }
        }
        $this->instance->directory->write(OperationJournal::CURRENT, (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Lets the child edit its staged compose document after the real render (a target that adds or changes a service). */
    public function editStagedCompose(Closure $edit, ?Closure $files = null): void
    {
        $this->stage = static function (string $root) use ($edit, $files): void {
            $compose = (string) file_get_contents($root.'/compose.yaml');
            $lines = explode("\n", $compose);
            $header = implode("\n", array_filter($lines, static fn (string $line): bool => str_starts_with($line, '#')));
            $document = json_decode(implode("\n", array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, '#'))), true);
            file_put_contents($root.'/compose.yaml', $header."\n".json_encode($edit($document), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            if ($files instanceof Closure) {
                $files($root);
            }
        };
    }

    /** @return array<string, string> env file name => contents, as the instance has them now */
    public function envFiles(): array
    {
        $files = [];
        foreach (glob($this->instance->directory->root.'/env/*.env') ?: [] as $path) {
            $files[basename($path)] = (string) file_get_contents($path);
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> the backups the seal one-off was asked to encrypt */
    public function sealed(): array
    {
        $calls = array_filter($this->instance->docker->called('composeRun'), static fn (array $call): bool => str_starts_with((string) ($call['command'][2] ?? ''), '--seal='));

        return array_values(array_map(static fn (array $call): string => substr((string) $call['command'][2], 7), $calls));
    }

    /** Turns encryption of backups on (the seal one-off then runs for safety backups). */
    public function encrypt(): void
    {
        $this->instance->directory->saveState($this->instance->state()->withRecipients(['age1'.str_repeat('c', 58)]));
    }

    /** `fieldset rollback --yes` with --json. */
    public function rollback(array $options = []): array
    {
        return $this->instance->run('fieldset:rollback', ['--yes' => true, ...$options]);
    }

    /** The labels a detached supervisor container carries. */
    public function supervisorLabels(): array
    {
        return Labels::forInstance($this->instance->state()->uuid(), 'supervisor');
    }
}
