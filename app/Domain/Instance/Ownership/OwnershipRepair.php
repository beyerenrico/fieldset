<?php

declare(strict_types=1);

namespace App\Domain\Instance\Ownership;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use Carbon\CarbonImmutable;

/**
 * `fieldset repair-ownership` (2.1.1 U4, R5, R28, KTD12): after Docker began to map user IDs differently (rootful became
 * rootless, the directory moved to WSL 2, …), gives the instance to the owner the shim measured in this run. Under the
 * instance lock, with no operation unfinished:
 *
 * 1. a root container gives the files to the new owner (OwnerFiles: owners only, no symlinks, one file system);
 * 2. the env files must still be 0600;
 * 3. the stack files are rendered for the new owner and the backup service is recreated, so its FIELDSET_OWNER_UID follows;
 * 4. state.json saves the new owner last: until then doctor keeps naming the mismatch, and running the repair again
 *    finishes it.
 *
 * Saved and measured owner already equal: nothing to do (`changed: false`). Docker volumes are never touched.
 */
final readonly class OwnershipRepair
{
    public const string OPERATION = 'repair-ownership';

    public const int WAIT_SECONDS = 120;

    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private ContainerIdentity $identity,
        private OperationJournal $journal,
        private OwnerFiles $files,
        private ComposeRenderer $renderer,
        private DockerGateway $docker,
    ) {}

    /** What this run would change; an instance that is not installed is exit 2, an unfinished install or adopt exit 9. */
    public function plan(HostFacts $facts): OwnerChange
    {
        return OwnerChange::plan(RestoreOperation::installed($this->directory), $facts);
    }

    public function repair(FieldsetContext $context): CommandResult
    {
        $change = $this->plan($context->facts);
        if (! $change->needed()) {
            return $this->unchanged($change);
        }
        $handle = $this->lock->acquire($this->identity->holder('fieldset', $context->facts->callerUid()), self::OPERATION);

        try {
            $open = $this->journal->current();
            if ($open instanceof JournalRecord) {
                throw new OperationUnfinished($open);
            }
            $state = $this->directory->state();
            $change = OwnerChange::plan($state, $context->facts);

            return $change->needed() ? $this->apply($state, $change, $context->progress) : $this->unchanged($change);
        } finally {
            $this->lock->release($handle);
        }
    }

    private function apply(InstanceState $state, OwnerChange $change, ProgressReporter $progress): CommandResult
    {
        $progress->phase('files', "Giving the instance files from UID {$change->from} to UID {$change->to} (as containers see them)");
        $given = $this->files->give($state, $this->directory->root, $change);
        OwnerFiles::assertSecretsPrivate($this->directory);
        $repaired = $change->applyTo($state, CarbonImmutable::now('UTC'));
        $this->recreateBackup($repaired, $progress);
        $this->directory->saveState($repaired);

        return CommandResult::ok(self::OPERATION, [
            ...$change->toArray(), 'files_changed' => $given, 'backup_service' => 'recreated',
            'message' => "The instance files belong to UID {$change->to} inside containers now, and the instance saved it as its owner.",
        ], changed: true);
    }

    /** Renders the stack for the new owner (only the backup service's env changes) and recreates the backup service. */
    private function recreateBackup(InstanceState $state, ProgressReporter $progress): void
    {
        $secrets = InstanceSecrets::fromEnvFiles(new EnvFiles($this->directory))
            ?? throw new InvalidState('The app env file lacks APP_KEY or the database password; they are never generated again silently.');
        $this->renderer->write($this->directory, $state, $secrets, ComposeFile::emulated($this->directory, $this->renderer, $state, $secrets));
        $progress->phase('backup', "Recreating the backup service for UID {$state->containerUid()}");

        try {
            $this->docker->composeUp(ComposeProject::forInstance($state, $this->directory), [BackupService::NAME], self::WAIT_SECONDS);
        } catch (DockerCommandFailed $e) {
            throw new InstanceException(
                'The backup service did not start again for the new owner: '.$e->outcome->stderrTail(3),
                ExitCode::HealthCheckFailed,
                'services_unhealthy',
                "The files already belong to UID {$state->containerUid()}; read `fieldset logs backup`, then run `fieldset repair-ownership --yes` again: it saves the new owner only once the backup service runs.",
                true,
                previous: $e,
                changed: true,
            );
        }
    }

    private function unchanged(OwnerChange $change): CommandResult
    {
        return CommandResult::ok(self::OPERATION, [
            ...$change->toArray(), 'files_changed' => 0, 'backup_service' => 'unchanged',
            'message' => "The instance files already belong to UID {$change->to} inside containers, as measured just now: nothing to repair.",
        ]);
    }
}
