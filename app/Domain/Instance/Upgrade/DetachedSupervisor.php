<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The foreground side of `fieldset upgrade` (KTD5, plan U18, ADR 0086). The upgrade runs in a detached ops container of the
 * **installed** image (`--rm`, `restart: no`, one per instance, labelled `supervisor`) with the Docker socket and the
 * instance directory at the same path, like the shim's ops container, so a closed SSH session or a killed foreground
 * `fieldset` never stops it half-way. The foreground follows the journal (one progress event per phase) until the
 * supervisor's container is gone, then renders the envelope the supervisor left in a 0600 result file. Without one the
 * supervisor stopped abnormally: `fieldset status` shows the journal and `fieldset upgrade --resume` continues.
 *
 * M12 gate O3: the result file stays until the next launch replaces it, named by `operations/supervisor.json` (container,
 * result file, start), so the outcome survives a killed foreground: `fieldset upgrade --follow` attaches to a supervisor that
 * still runs (or returns the last result when none runs), and `fieldset status` shows it as `last_upgrade`. `--resume` with no
 * open journal is exit 2 `nothing_to_resume`, never a new upgrade.
 */
final readonly class DetachedSupervisor
{
    /** The supervisor's environment variable naming its result file (relative to the instance directory). */
    public const string RESULT_ENV = 'FIELDSET_SUPERVISOR_RESULT';

    public const string RESULT_PATTERN = '/^operations\/supervisor-[0-9a-f]{16}\.json$/D';

    public const int FOLLOW_SECONDS = 2;

    /** Names the last launched supervisor's container and result file (O3). */
    public const string POINTER = InstanceDirectory::OPERATIONS_DIR.'/supervisor.json';

    /** Variables passed on from the foreground's own environment: harness-only switches (ADR 0070, 0075, 0082). */
    private const array PASSED = ['FIELDSET_TRUST_KEY', 'FIELDSET_TEST_DNS', 'FIELDSET_TEST_TUNNEL'];

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private DockerGateway $docker,
        private OperationJournal $journal,
    ) {}

    public function launchAndFollow(UpgradeRequest $request, HostFacts $facts, ProgressReporter $progress): CommandResult
    {
        $state = RestoreOperation::installed($this->directory);
        if ($request->resume && ! $this->journal->current() instanceof JournalRecord) {
            throw new InvalidInput('There is no interrupted upgrade to resume.', 'nothing_to_resume', 'Run `fieldset upgrade` (with --to <version> for a specific release) to start one; `fieldset upgrade --follow` shows the last upgrade\'s result.');
        }
        $result = 'operations/supervisor-'.bin2hex(random_bytes(8)).'.json';
        $this->directory->ensureDirectory(InstanceDirectory::OPERATIONS_DIR);
        $container = $this->supervisor->launchDetached($state, $this->spec($state, $request, $facts, $result));
        $this->point($container, $result);
        $progress->phase('supervisor', 'The upgrade runs in the background container '.$state->project().'-'.Supervisor::ROLE.'; following it (stopping this command does not stop it; `fieldset upgrade --follow` attaches again).');

        return $this->follow($container, $result, $progress);
    }

    /**
     * `fieldset upgrade --follow` (O3): attaches to the running supervisor and returns its envelope; with none running, the last
     * upgrade's result (exit 2 `nothing_to_follow` when no upgrade ever ran here).
     */
    public function attach(ProgressReporter $progress): CommandResult
    {
        $state = RestoreOperation::installed($this->directory);
        $pointer = $this->pointer();
        $running = $this->running($state);
        if ($running instanceof ContainerInfo) {
            $progress->phase('supervisor', "Following the upgrade in the background container {$running->name} (stopping this command does not stop it).");

            $same = $pointer['container'] !== null && (str_starts_with($pointer['container'], $running->id) || str_starts_with($running->id, $pointer['container'])); // listings carry the short ID

            return $this->follow($running->id, $same ? $pointer['result'] : null, $progress);
        }
        if ($pointer['result'] === null) {
            throw new InvalidInput('No upgrade runs, and none ran on this instance yet.', 'nothing_to_follow', 'Run `fieldset upgrade` to start one.');
        }

        return $this->outcome($pointer['result']);
    }

    /**
     * The last upgrade's outcome for `fieldset status` (O3): exit code, status, error codes, when it finished and the result
     * file that holds its whole envelope; null when none ran or its result is missing.
     *
     * @return array{exit_code: int, status: string, codes: list<string>, from: mixed, to: mixed, finished_at: string|null, result_file: string}|null
     */
    public function last(): ?array
    {
        $file = $this->pointer()['result'];
        $document = $file === null ? null : $this->document($file);
        if ($document === null) {
            return null;
        }
        $envelope = $document['envelope'];
        $errors = is_array($envelope['errors'] ?? null) ? $envelope['errors'] : [];

        return [
            'exit_code' => $document['exit_code'], 'status' => is_string($envelope['status'] ?? null) ? $envelope['status'] : 'unknown',
            'codes' => array_values(array_map(static fn (mixed $error): string => is_array($error) ? (string) ($error['code'] ?? 'unknown') : 'unknown', $errors)),
            'from' => $envelope['data']['from'] ?? null, 'to' => $envelope['data']['to'] ?? null,
            'finished_at' => is_string($document['finished_at'] ?? null) ? $document['finished_at'] : null, 'result_file' => $file,
        ];
    }

    /** The running supervisor container of this instance, if any (`fieldset status` shows it, O4). */
    public function running(InstanceState $state): ?ContainerInfo
    {
        $running = array_values(array_filter($this->docker->containers(Labels::forInstance($state->uuid(), Supervisor::ROLE), all: false), static fn (ContainerInfo $c): bool => $c->running()));

        return $running[0] ?? null;
    }

    /**
     * The supervisor container: the installed app image as `fieldset upgrade --supervise`, the socket and the instance
     * directory (and the launcher's file mounts) at the same paths, the host facts as FIELDSET_* variables, no nonce (the
     * launch checks ran in the foreground).
     */
    public function spec(InstanceState $state, UpgradeRequest $request, HostFacts $facts, string $result): ContainerSpec
    {
        $root = $this->directory->root;
        $volumes = [($facts->socket ?? '/var/run/docker.sock').':/var/run/docker.sock', $root.':'.$root];
        foreach ($facts->mounts as $path => $writable) {
            $volumes[] = $path.':'.$path.($writable ? '' : ':ro');
        }
        $socketGroup = @filegroup('/var/run/docker.sock');

        return new ContainerSpec(
            image: $state->digests()['app'], command: ['upgrade', '--supervise', '--dir', $root, ...$request->flags(), '--json'],
            env: $this->environment($facts, $result), volumes: $volumes,
            user: (function_exists('posix_getuid') ? posix_getuid() : 0).':'.(function_exists('posix_getgid') ? posix_getgid() : 0),
            entrypoint: 'fieldset', workdir: $root, groupAdd: is_int($socketGroup) && $socketGroup > 0 ? [(string) $socketGroup] : [],
        );
    }

    /** The supervisor's side: the result envelope (redacted) for the foreground, when the foreground named a file. */
    public function report(CommandResult $result, SecretRedactor $redactor): void
    {
        $file = getenv(self::RESULT_ENV);
        if (! is_string($file) || preg_match(self::RESULT_PATTERN, $file) !== 1) {
            return;
        }
        $this->directory->write($file, json_encode(['exit_code' => $result->exitCode->value, 'finished_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'envelope' => $result->toArray($redactor)], CommandResult::JSON_FLAGS)."\n", 0600);
    }

    private function follow(string $container, ?string $result, ProgressReporter $progress): CommandResult
    {
        $seen = [];
        while (($info = $this->docker->inspectContainer($container)) instanceof ContainerInfo && $info->running()) {
            $this->phases($progress, $seen);
            Sleep::for(self::FOLLOW_SECONDS)->seconds();
        }
        $this->phases($progress, $seen);

        return $this->outcome($result);
    }

    /** The envelope a supervisor left (the file stays for `fieldset status` and `--follow`), or `supervisor_lost`. */
    private function outcome(?string $result): CommandResult
    {
        $document = $result === null ? null : $this->document($result);
        if ($document === null) {
            return CommandResult::failed('upgrade', ExitCode::InternalError, 'supervisor_lost', 'The upgrade supervisor stopped without a result.',
                'Run `fieldset status`: it shows the upgrade journal\'s last phase; `fieldset upgrade --resume` continues an interrupted upgrade.', true, [], changed: true);
        }

        return CommandResult::fromArray($document['envelope'], ExitCode::tryFrom($document['exit_code']) ?? ExitCode::InternalError);
    }

    /** @return array{exit_code: int, envelope: array<string, mixed>, finished_at?: mixed}|null */
    private function document(string $result): ?array
    {
        $raw = preg_match(self::RESULT_PATTERN, $result) === 1 ? $this->resultFile($result) : null;
        $document = $raw === null ? null : json_decode($raw, true);

        return is_array($document) && is_int($document['exit_code'] ?? null) && is_array($document['envelope'] ?? null) ? $document : null;
    }

    /**
     * Records the new supervisor and removes the result its predecessor left (replaced, O3).
     */
    private function point(string $container, string $result): void
    {
        $previous = $this->pointer()['result'];
        if ($previous !== null && $previous !== $result) {
            $this->directory->files()->delete($previous);
        }
        $this->directory->write(self::POINTER, json_encode(['container' => $container, 'result' => $result, 'started_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString()], CommandResult::JSON_FLAGS)."\n", 0600);
    }

    /** @return array{container: string|null, result: string|null} */
    private function pointer(): array
    {
        try {
            $document = json_decode((string) $this->directory->read(self::POINTER), true);
        } catch (Throwable) {
            $document = null;
        }
        $result = is_array($document) && is_string($document['result'] ?? null) && preg_match(self::RESULT_PATTERN, $document['result']) === 1 ? $document['result'] : null;

        return ['container' => is_array($document) && is_string($document['container'] ?? null) ? $document['container'] : null, 'result' => $result];
    }

    /**
     * The supervisor's result file. A read can fail for a moment where the instance directory reaches containers
     * through a file-sharing layer (Docker Desktop's virtiofs): retried a few times before the result counts as missing.
     */
    private function resultFile(string $result): ?string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->directory->read($result);
            } catch (Throwable) {
                if ($attempt >= 5) {
                    return null;
                }
                Sleep::for(1)->second();
            }
        }
    }

    /**
     * One progress event per new phase state of the open upgrade journal.
     *
     * @param  array<string, true>  $seen
     */
    private function phases(ProgressReporter $progress, array &$seen): void
    {
        try {
            $record = $this->journal->current();
        } catch (Throwable) {
            return; // the supervisor replaces the journal file while we read it (a shared mount): the next poll reads it
        }
        if (! $record instanceof JournalRecord || $record->operation() !== UpgradeJournal::OPERATION) {
            return;
        }
        foreach ($record->phases() as $phase) {
            $key = $phase['name'].'#'.$phase['attempt'].':'.$phase['status'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $level = $phase['status'] === 'failed' ? ProgressReporter::ERROR : ProgressReporter::INFO;
            $progress->event($phase['name'], $level, match ($phase['status']) {
                'completed' => "Completed {$phase['name']}", 'failed' => "{$phase['name']} failed", default => "Starting {$phase['name']}",
            });
        }
    }

    /** @return array<string, string> */
    private function environment(HostFacts $facts, string $result): array
    {
        $env = array_filter([
            'FIELDSET_HOST_UID' => $facts->uid === null ? null : (string) $facts->uid, 'FIELDSET_HOST_GID' => $facts->gid === null ? null : (string) $facts->gid,
            'FIELDSET_HOST_OS' => $facts->os, 'FIELDSET_INSTANCE_DIR' => $this->directory->root, 'FIELDSET_LAN_ADDRESSES' => implode(' ', $facts->lanAddresses),
            'FIELDSET_TZ' => $facts->timezone, 'FIELDSET_DOCKER_RUNTIME' => $facts->runtime?->value, 'FIELDSET_DOCKER_SOCKET' => $facts->socket,
            'FIELDSET_CONTAINER_UID' => $facts->containerUid === null ? null : (string) $facts->containerUid,
            'FIELDSET_REGISTRY' => $facts->registry, 'FIELDSET_RELEASE_URL' => $facts->releaseUrl, 'DOCKER_DEFAULT_PLATFORM' => $facts->platform,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
        foreach (self::PASSED as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $env[$name] = $value;
            }
        }

        // HOME and DOCKER_CONFIG under /tmp (KTD2): the image's /tmp exists and belongs to the container alone
        return [...$env, 'HOME' => '/tmp', 'DOCKER_CONFIG' => '/tmp/.docker', 'DOCKER_HOST' => 'unix:///var/run/docker.sock', self::RESULT_ENV => $result];
    }
}
