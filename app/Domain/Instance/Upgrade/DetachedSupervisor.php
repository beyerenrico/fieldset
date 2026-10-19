<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The foreground side of `fsa upgrade` (KTD5, plan U18, ADR 0086). The upgrade runs in a detached ops container of the
 * **installed** image (`--rm`, `restart: no`, one per instance, labelled `supervisor`) with the Docker socket and the
 * instance directory at the same path, like the shim's ops container, so a closed SSH session or a killed foreground
 * `fsa` never stops it half-way. The foreground follows the journal (one progress event per phase) until the
 * supervisor's container is gone, then renders the envelope the supervisor left in a 0600 result file. Without one the
 * supervisor stopped abnormally: `fsa status` shows the journal and `fsa upgrade --resume` continues.
 */
final readonly class DetachedSupervisor
{
    /** The supervisor's environment variable naming its result file (relative to the instance directory). */
    public const string RESULT_ENV = 'FSA_SUPERVISOR_RESULT';

    public const string RESULT_PATTERN = '/^operations\/supervisor-[0-9a-f]{16}\.json$/D';

    public const int FOLLOW_SECONDS = 2;

    /** Variables passed on from the foreground's own environment: harness-only switches (ADR 0070, 0075, 0082). */
    private const array PASSED = ['FSA_TRUST_KEY', 'FSA_TEST_DNS', 'FSA_TEST_TUNNEL'];

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private DockerGateway $docker,
        private OperationJournal $journal,
    ) {}

    public function launchAndFollow(UpgradeRequest $request, HostFacts $facts, ProgressReporter $progress): CommandResult
    {
        $state = RestoreOperation::installed($this->directory);
        $result = 'operations/supervisor-'.bin2hex(random_bytes(8)).'.json';
        $this->directory->ensureDirectory(InstanceDirectory::OPERATIONS_DIR);
        $container = $this->supervisor->launchDetached($state, $this->spec($state, $request, $facts, $result));
        $progress->phase('supervisor', 'The upgrade runs in the background container '.$state->project().'-'.Supervisor::ROLE.'; following it (stopping this command does not stop it).');

        return $this->follow($container, $result, $progress);
    }

    /**
     * The supervisor container: the installed app image as `fsa upgrade --supervise`, the socket and the instance
     * directory (and the launcher's file mounts) at the same paths, the host facts as FSA_* variables, no nonce (the
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
            entrypoint: 'fsa', workdir: $root, groupAdd: is_int($socketGroup) && $socketGroup > 0 ? [(string) $socketGroup] : [],
        );
    }

    /** The supervisor's side: the result envelope (redacted) for the foreground, when the foreground named a file. */
    public function report(CommandResult $result, SecretRedactor $redactor): void
    {
        $file = getenv(self::RESULT_ENV);
        if (! is_string($file) || preg_match(self::RESULT_PATTERN, $file) !== 1) {
            return;
        }
        $this->directory->write($file, json_encode(['exit_code' => $result->exitCode->value, 'envelope' => $result->toArray($redactor)], CommandResult::JSON_FLAGS)."\n", 0600);
    }

    private function follow(string $container, string $result, ProgressReporter $progress): CommandResult
    {
        $seen = [];
        while (($info = $this->docker->inspectContainer($container)) instanceof ContainerInfo && $info->running()) {
            $this->phases($progress, $seen);
            Sleep::for(self::FOLLOW_SECONDS)->seconds();
        }
        $this->phases($progress, $seen);
        $raw = $this->resultFile($result);
        if ($raw !== null) {
            $this->directory->files()->delete($result);
        }
        $document = $raw === null ? null : json_decode($raw, true);
        if (! is_array($document) || ! is_int($document['exit_code'] ?? null) || ! is_array($document['envelope'] ?? null)) {
            return CommandResult::failed('upgrade', ExitCode::InternalError, 'supervisor_lost', 'The upgrade supervisor stopped without a result.',
                'Run `fsa status`: it shows the upgrade journal\'s last phase; `fsa upgrade --resume` continues an interrupted upgrade.', true, [], changed: true);
        }

        return CommandResult::fromArray($document['envelope'], ExitCode::tryFrom($document['exit_code']) ?? ExitCode::InternalError);
    }

    /**
     * One progress event per new phase state of the open upgrade journal.
     *
     * @param  array<string, true>  $seen
     */
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
            'FSA_HOST_UID' => $facts->uid === null ? null : (string) $facts->uid, 'FSA_HOST_GID' => $facts->gid === null ? null : (string) $facts->gid,
            'FSA_HOST_OS' => $facts->os, 'FSA_INSTANCE_DIR' => $this->directory->root, 'FSA_LAN_ADDRESSES' => implode(' ', $facts->lanAddresses),
            'FSA_TZ' => $facts->timezone, 'FSA_DOCKER_RUNTIME' => $facts->runtime?->value, 'FSA_DOCKER_SOCKET' => $facts->socket,
            'FSA_CONTAINER_UID' => $facts->containerUid === null ? null : (string) $facts->containerUid,
            'FSA_REGISTRY' => $facts->registry, 'FSA_RELEASE_URL' => $facts->releaseUrl, 'DOCKER_DEFAULT_PLATFORM' => $facts->platform,
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
