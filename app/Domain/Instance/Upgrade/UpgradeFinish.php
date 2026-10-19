<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreSource;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Throwable;

/**
 * KTD19 steps 9 and 10 (R57, plan U18, ADR 0086). `commit()` writes the state: the target's version and digests, the
 * previous release (version, digests and the compose file it ran with) for `fsa rollback`, and the history entry; from
 * here on nothing restores automatically. `open()` lowers the gate, starts queue, scheduler and backup (containers of
 * services the target no longer has go), removes the pre-upgrade and pre-rollback safety backups this one supersedes
 * (R57: they replace each other only after a commit), seals this one when encryption is on, keeps the release files of
 * the previous release (`operations/previous-release`, for `fsa rollback`) and removes the stage and the scratch
 * directory. It repeats safely: a crash while opening is
 * finished by the next `fsa upgrade`; a failure is reported (exit 4), never rolled back.
 */
final readonly class UpgradeFinish
{
    public const int WAIT_SECONDS = 300;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private BackupStore $store,
        private SafetyBackup $safety,
        private RestoreSource $source,
        private InstanceLock $lock,
        private ReleaseFiles $files,
        private SafetySeal $seals,
    ) {}

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $backup  the journaled safety backup
     * @return array{version: string}
     */
    public function commit(InstanceState $state, array $release, array $backup, string $journal): array
    {
        $version = (string) $release['version'];
        if ($state->version() === $version) {
            return ['version' => $version]; // a resumed commit: written already
        }
        $previous = UpgradeJournal::strings($release['previous_digests'] ?? null);
        $committed = $state->withRelease($version, UpgradeJournal::strings($release['digests'] ?? null))
            ->withPreviousRelease((string) ($release['from'] ?? ''), $previous, (string) ($release['previous_compose'] ?? ''))
            ->withHistoryEntry('upgraded', ['from' => is_string($release['from'] ?? null) ? $release['from'] : null, 'to' => $version, 'journal' => $journal,
                'safety_backup' => is_string($backup['backup'] ?? null) ? $backup['backup'] : null]);
        $this->directory->saveState($committed);

        return ['version' => $version];
    }

    /**
     * @param  array<string, mixed>  $backup  the journaled safety backup
     * @return array{removed_safety_backups: list<string>, sealed: bool, warnings: list<array{0: string, 1: string, 2: string|null}>}
     *
     * @throws InstanceException exit 4 `open_failed` (the journal stays open; `fsa upgrade --resume` finishes)
     */
    public function open(InstanceState $state, ComposeProject $project, array $backup, string $id): array
    {
        new RuntimeWriter($this->directory)->lowerGate($state);
        try {
            $this->docker->composeUp($project, UpgradeQuiesce::WORKERS, self::WAIT_SECONDS, removeOrphans: true); // services the target dropped go
        } catch (DockerCommandFailed $e) {
            throw new InstanceException('The upgrade is committed and the app takes requests, but queue, scheduler or backup did not start.', ExitCode::HealthCheckFailed, 'open_failed',
                'Read `fsa logs queue`, fix the cause, then run `fsa upgrade --resume` to finish opening (nothing is restored).', true, ['docker' => $e->outcome->stderrTail(5)], $e);
        }
        $current = is_string($backup['backup'] ?? null) ? $backup['backup'] : null;
        $warnings = [];
        $removed = $this->seals->removeSuperseded([$current], $warnings); // pre-upgrade ones only (R57, M12 gate D1)
        $sealed = $this->seal($current, $warnings);
        UpgradeFiles::remove($this->directory->path(InstanceDirectory::OPERATIONS_DIR.'/upgrade-'.$id));
        $this->files->promote(UpgradeJournal::filesBefore($id), ReleaseFiles::PREVIOUS); // the previous release's files, for fsa rollback
        $this->discardScratch($id);

        return ['removed_safety_backups' => $removed, 'sealed' => $sealed, 'warnings' => $warnings];
    }

    /** @param  list<array{0: string, 1: string, 2: string|null}>  $warnings */
    private function seal(?string $current, array &$warnings): bool
    {
        $lock = $this->lock->current();
        if ($current === null || ! $lock instanceof LockRecord || ! $this->store->find($current) instanceof BackupEntry) {
            return false;
        }
        try {
            return $this->safety->seal($this->directory->state(), $current, new LockHandle($lock))['encrypted'];
        } catch (Throwable $e) {
            $warnings[] = ['safety_backup_not_sealed', "The safety backup {$current} stays unencrypted (0600): ".$e->getMessage(), 'Encrypt or delete it by hand.'];

            return false;
        }
    }

    private function discardScratch(string $id): void
    {
        try {
            $this->source->discard($id);
        } catch (Throwable) {
            // left for `fsa status` to report (restore_scratch_left)
        }
    }
}
