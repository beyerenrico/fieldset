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
 * the scratch database, role, volume and directory go and the previous stack starts again; from the swap (or a return
 * that began) until the gate went down the previous database and volume come back (RestoreRecovery, also after an
 * exit 8); once the gate went down the restored instance took requests, so lowering and cleaning up are finished
 * instead. Either way the journal closes, and the safety backup is sealed.
 */
final readonly class RestoreAbandon
{
    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private RestoreRecovery $recovery,
        private RestoreFinale $finale,
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
        $safety = RestoreFinale::safety($record);
        if ($names->id === '' || (! $record->hasStarted('swap') && ! $record->hasStarted('return'))) {
            $closed = $this->recovery->goBack($run, $state, $project, $names, $record->hasStarted('quiesce')); // journaled (M2)
            $result = CommandResult::ok(RestoreOperation::OPERATION, ['abandoned' => true, 'swapped' => false, 'left_behind' => $closed['left_behind']], changed: true);

            return $this->recovery->sealed(RestoreRecovery::degraded($result, $closed['degraded']), $safety);
        }
        $forward = $this->recovery->noted($run, RestoreRecovery::NO_WAY_BACK); // no way back: the restored data is all there is (L4)
        if ($forward || $record->hasStarted('gate') || $record->hasStarted('cleanup')) { // or the restored instance took requests
            $result = $this->finale->open($run, RestoreFinale::swappedState($state, $names), RestoreFinale::previousState($state, $names, $record), $project, $names, $record->phaseData('prepare'), wayBack: ! $forward);

            return $result->exitCode === ExitCode::Success ? $result->withData(['abandoned' => false, 'completed' => true, 'forward' => $forward]) : $result;
        }
        $added = RestoreNames::strings($record->phaseData('swap')['keys_added'] ?? ($record->phaseData('prepare')['add'] ?? null));
        $result = $this->recovery->returnToPrevious($run, RestoreFinale::previousState($state, $names, $record), $project, $names, new InstanceException('Abandoned.', ExitCode::RolledBack, 'restore_abandoned'), $added, $safety, $record->hasCompleted('swap'));

        return $result->exitCode === ExitCode::RolledBack
            ? CommandResult::ok(RestoreOperation::OPERATION, ['abandoned' => true, 'swapped' => true, 'returned_to_previous' => true, ...$result->data], changed: true)
            : $result;
    }
}
