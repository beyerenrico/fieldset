<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa restore --abandon` (R54, plan U17, ADR 0085): ends a restore that stopped half-way, by its journal. Before the swap
 * the scratch database, role, volume and directory go and the previous stack starts again; after a swap that never
 * passed its verification the previous database and volume come back (RestoreRecovery); after a verified swap only
 * opening and cleaning up are left, and they are finished. Either way the journal closes.
 */
final readonly class RestoreAbandon
{
    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private RestoreRecovery $recovery,
        private RestoreStack $stack,
        private RestoreCleanup $cleanup,
    ) {}

    public function abandon(FsaContext $context): CommandResult
    {
        $current = $this->journal->current();
        if (! $current instanceof JournalRecord || $current->operation() !== RestoreOperation::OPERATION) {
            throw new InvalidInput('There is no interrupted restore to abandon.', 'nothing_to_abandon', 'Run `fsa status` to see what, if anything, is unfinished.');
        }
        $state = $this->directory->state();

        return $this->supervisor->run(
            RestoreOperation::OPERATION, $this->identity->holder('fsa', $context->facts->callerUid()),
            fn (OperationRun $run): CommandResult => $this->steps($run, $state), resume: true, progress: $context->progress,
        );
    }

    private function steps(OperationRun $run, InstanceState $state): CommandResult
    {
        $record = $run->record();
        $names = RestoreNames::fromArray($record->phaseData('plan'));
        $project = ComposeProject::forInstance($state, $this->directory);
        if ($names->id === '' || ! $record->hasStarted('swap')) {
            $left = $names->id === '' ? [] : $this->recovery->dropScratch($project, $names);
            if ($record->hasStarted('quiesce')) {
                $this->stack->reopen($state, $project);
            }

            return CommandResult::ok(RestoreOperation::OPERATION, ['abandoned' => true, 'swapped' => false, 'left_behind' => $left], changed: true);
        }
        if (! $record->hasCompleted('verify')) {
            $default = $state->project().'_app-storage';
            $previous = $state->withSetting('storage_volume', $names->currentVolume === $default ? null : $names->currentVolume);
            $added = RestoreNames::strings($record->phaseData('swap')['keys_added'] ?? null);
            $safety = $record->phaseData('safety_backup')['backup'] ?? null;
            $result = $this->recovery->returnToPrevious($previous, $project, $names, new InstanceException('Abandoned.', ExitCode::RolledBack, 'restore_abandoned'), $added, is_string($safety) ? $safety : null);

            return $result->exitCode === ExitCode::RolledBack
                ? CommandResult::ok(RestoreOperation::OPERATION, ['abandoned' => true, 'swapped' => true, 'returned_to_previous' => true, ...$result->data], changed: true)
                : $result;
        }
        $this->stack->reopen($state, $project);
        $safety = $record->phaseData('safety_backup')['backup'] ?? null;
        $backup = $record->phaseData('prepare')['backup']['name'] ?? null;
        $cleanup = $this->cleanup->run($project, $names, is_string($safety) ? $safety : null, $record, is_string($backup) ? $backup : null);

        return CommandResult::ok(RestoreOperation::OPERATION, ['abandoned' => false, 'completed' => true, 'left_behind' => $cleanup['left_behind']], changed: true);
    }
}
