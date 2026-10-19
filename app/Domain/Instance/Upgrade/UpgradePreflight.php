<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\Checks\ArchitectureCheck;
use App\Domain\Instance\Doctor\Checks\DockerVersionCheck;
use App\Domain\Instance\Doctor\Checks\RegistryCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\ReleaseSource;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreSpace;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * KTD19 steps 1 and 2 of an upgrade (plan U18, ADR 0086), all before anything changes: the target release (downloaded
 * and verified, ReleaseFeed) and its path from the installed version (UpgradePath); the doctor (Docker versions, the
 * architecture against the release's platforms, the registries of images still to pull) and the free space for the
 * safety backup, a second database and a second storage volume (exit 3); then every pinned digest, the target app
 * image first. A failure here is exit 9 or its own pre-change code (2, 3, 7, 10), with nothing stopped.
 */
final readonly class UpgradePreflight
{
    /** A safety backup, a database three times its dump, a storage volume as large as its archive: five backups. */
    public const int SPACE_FACTOR = 5;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private ReleaseFeed $feed,
        private DoctorProbes $probes,
        private BackupStore $store,
    ) {}

    /**
     * The target release as the journal keeps it (phase `release`): version, the image references to run (with the
     * mirror), the previous release's digests and compose file, the platforms and the notes URL.
     *
     * @return array<string, mixed>
     */
    public function release(InstanceState $state, HostFacts $facts, UpgradeRequest $request): array
    {
        $manifest = $this->feed->fetch($this->directory, $facts, $request->to);
        $current = UpgradePath::assess($state->version(), $manifest, $request->major);

        return [
            'version' => (string) $manifest->version, 'from' => $state->version(), 'current' => ! $current,
            'digests' => ReleaseSource::digests($manifest, $facts->registry), 'previous_digests' => $state->digests(),
            'previous_compose' => (string) $this->directory->read(InstanceDirectory::COMPOSE_FILE),
            'platforms' => $manifest->platforms, 'notes_url' => $manifest->notesUrl, 'upgrades_from' => (string) $manifest->upgradesFrom,
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed> the checks and the space estimate (phase `doctor`)
     *
     * @throws InstanceException exit 3 (a check failed, too little space), 10 (Docker too old)
     */
    public function doctor(InstanceState $state, HostFacts $facts, array $release): array
    {
        $images = array_values(UpgradeJournal::strings($release['digests'] ?? null));
        $context = new DoctorContext($facts, $this->directory, $this->docker, $this->probes, $state, images: $images, platforms: UpgradeJournal::list($release['platforms'] ?? null));
        $results = array_map(static fn ($check): CheckResult => $check->run($context), [new DockerVersionCheck, new ArchitectureCheck, new RegistryCheck]);
        foreach ($results as $result) {
            if ($result->status->value === 'fail') {
                $tooOld = ($result->details['too_old'] ?? false) === true;
                throw new InstanceException($result->summary, $tooOld ? ExitCode::DockerUnavailable : ExitCode::PreflightFailed, $tooOld ? 'docker_too_old' : 'preflight_failed', $result->hint, false, ['check' => $result->key]);
            }
        }

        return ['checks' => array_map(static fn (CheckResult $result): array => $result->toArray(), $results), 'space' => $this->space()];
    }

    /**
     * Pulls every digest that is not on the host yet, the target app image first (R34).
     *
     * @param  array<string, mixed>  $release
     * @return array{pulled: list<string>}
     */
    public function pull(array $release): array
    {
        $digests = UpgradeJournal::strings($release['digests'] ?? null);
        $order = array_values(array_unique([...(isset($digests['app']) ? [$digests['app']] : []), ...array_values($digests)]));
        $pulled = [];
        foreach ($order as $reference) {
            if ($this->docker->inspectImage($reference) instanceof ImageInfo) {
                continue;
            }
            try {
                $this->docker->pullImage($reference);
            } catch (DockerCommandFailed $e) {
                throw new InstanceException("Cannot pull {$reference}: ".$e->outcome->stderrTail(2), ExitCode::AbortedUnchanged, 'pull_failed', 'Check the network and FSA_REGISTRY, then run `fsa upgrade` again; nothing was changed.', true, [], $e);
            }
            $pulled[] = $reference;
        }

        return ['pulled' => $pulled];
    }

    /** Whether the instance renders the backup service the safety backup runs in (exit 3 otherwise). */
    public function assertBackupService(): void
    {
        if (! in_array('backup', ComposeFile::services($this->directory), true)) {
            throw new InstanceException('This instance has no backup service, so no safety backup can be taken.', ExitCode::PreflightFailed, 'backup_service_missing', 'Run `fsa install` again with the same flags to render it, then upgrade.');
        }
    }

    /**
     * Free space on the instance directory's file system for five times the newest backup (safety backup, a second
     * database, a second storage volume) plus the restore's headroom; unknown free space passes with a warning.
     *
     * @return array{required: int, free: int|null}
     *
     * @throws InstanceException exit 3 `low_disk_space`
     */
    private function space(): array
    {
        $entries = $this->store->entries();
        $required = self::SPACE_FACTOR * ($entries === [] ? 0 : $entries[0]->bytes) + RestoreSpace::HEADROOM;
        $probe = app()->bound(RestoreSpace::FREE_BYTES) ? app(RestoreSpace::FREE_BYTES) : null;
        $free = $probe instanceof Closure ? $probe() : $this->store->freeBytes();
        if (is_int($free) && $free < $required) {
            throw new InstanceException(
                sprintf('Not enough free disk space for the upgrade: %d MiB needed (a safety backup, a second database and storage volume, headroom), %d MiB free.', intdiv($required, 1024 ** 2), intdiv($free, 1024 ** 2)),
                ExitCode::PreflightFailed, 'low_disk_space', 'Free space on the disk that holds the instance and Docker (old backups in backups/), then run `fsa upgrade` again; nothing was changed.', false,
                ['required_bytes' => $required, 'free_bytes' => $free],
            );
        }

        return ['required' => $required, 'free' => is_int($free) ? $free : null];
    }
}
