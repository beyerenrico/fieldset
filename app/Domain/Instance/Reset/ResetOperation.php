<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\RecordDigest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ConfirmationRequired;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\RestoreHalt;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Closure;
use Throwable;

/**
 * `fsa reset` (§7, R43, R57, R68, KTD26, plan U23, ADR 0088) under the instance lock, journaled phase by phase. Up to the
 * drop nothing is lost:
 *
 * plan (the live database read: owner, organization; exit 3 when no owner exists) → quiesce (like a restore: gate up,
 * writers stopped, the queue drained, pending audit entries sealed) → ledger (the final chain heads, sequence values and
 * organization counts, before the database goes) → safety_backup (unless `--no-backup`) or audit_export (`--no-backup`:
 * written while real organizations exist) → recreate_database (every writer stopped first; repeated until the new epoch
 * began) → … ResetRebuild from `migrate` on.
 *
 * Before the drop a failure (or a signal, at a phase boundary) takes the journaled way back (ResetWayBack: exit 5, or
 * exit 8 with every writer stopped); a run that finds it begun only finishes it, and `--abandon` takes it on request.
 * A resumed run that finds writers running (an earlier way back that started them) takes the heads, the backup and the
 * export again. From the drop on the reset only goes forward: the next `fsa reset` with the same flags continues
 * without asking. A new journal needs the operator's yes, checked again under the lock (review S4).
 */
final readonly class ResetOperation
{
    public const string OPERATION = 'reset';

    /** The first phase that deletes: a reset that began it (and reached the database) is only ever finished. */
    public const string DROP = 'recreate_database';

    /** Journal note: the first drop failed while the old database still answered (review A3): nothing was deleted. */
    public const string DROP_NOT_REACHED = 'drop_not_reached';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private InstanceLock $lock,
        private OperationJournal $journal,
        private DockerGateway $docker,
        private RestoreStack $stack,
        private SafetyBackup $safety,
        private Ledger $ledger,
        private ResetApp $app,
        private ResetRebuild $rebuild,
        private ResetWayBack $wayBack,
        private Interruption $interruption,
    ) {}

    public function reset(ResetRequest $request, FsaContext $context, bool $secretsToFile): CommandResult
    {
        $state = RestoreOperation::installed($this->directory);
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));

        return $this->interruption->guard(fn (): CommandResult => $this->supervisor->run(
            self::OPERATION, $this->identity->holder('fsa', $request->actorUid),
            fn (OperationRun $run): CommandResult => $this->steps($run, $state, $request, $context, $secretsToFile, $digest),
            resume: true, context: $request->toContext(), progress: $context->progress, archive: $this->archive($digest),
            accepts: static fn (JournalRecord $record): bool => $request->sameAs($record->context()),
        ));
    }

    /**
     * `fsa reset --abandon` (review A2): ends an interrupted reset before its drop by the journaled way back. After the
     * drop there is nothing to go back to (exit 2, the hint names the command that finishes it).
     */
    public function abandon(FsaContext $context): CommandResult
    {
        $open = $this->journal->current();
        if (! $open instanceof JournalRecord || $open->operation() !== self::OPERATION) {
            throw new InvalidInput('There is no interrupted reset to abandon.', 'nothing_to_abandon', 'Run `fsa status` to see what, if anything, is unfinished.');
        }
        if (self::dropBegan($open)) {
            throw new InvalidInput('The interrupted reset recreated the database already; there is nothing to go back to.', 'reset_past_drop', ResetStatus::hint($open));
        }
        $state = RestoreOperation::installed($this->directory);
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));

        return $this->supervisor->run(
            self::OPERATION, $this->identity->holder('fsa', $context->facts->callerUid()),
            fn (OperationRun $run): CommandResult => $this->wayBack->abandon($run, $state, ComposeProject::forInstance($state, $this->directory)),
            resume: true, progress: $context->progress, archive: $this->archive($digest),
        );
    }

    /** Whether an open reset journal already began deleting (its resume asks nothing, `--abandon` refuses it). */
    public static function pastDrop(?JournalRecord $open): bool
    {
        return $open?->operation() === self::OPERATION && self::dropBegan($open);
    }

    /** Whether the drop began and reached the database (a first drop that failed while it still answered did not, A3). */
    public static function dropBegan(JournalRecord $record): bool
    {
        return $record->hasStarted(self::DROP) && ! array_any($record->events(), static fn (array $event): bool => $event['type'] === self::DROP_NOT_REACHED);
    }

    /** An open reset that deletes nothing more when continued: past the drop, or going back. */
    public static function continuesWithoutQuestion(?JournalRecord $open): bool
    {
        return $open?->operation() === self::OPERATION && (self::dropBegan($open) || $open->hasStarted(ResetWayBack::PHASE));
    }

    /** @return Closure(JournalRecord): JournalRecord the archived journal keeps the owner's e-mail and name as digests */
    private function archive(?RecordDigest $digest): Closure
    {
        return static fn (JournalRecord $record): JournalRecord => $record->withValuesReplaced(ResetSubject::PERSONAL, static fn (string $value): ?string => $digest?->digest($value));
    }

    private function steps(OperationRun $run, InstanceState $state, ResetRequest $request, FsaContext $context, bool $toFile, ?RecordDigest $digest): CommandResult
    {
        if (! $request->confirmed && ! self::continuesWithoutQuestion($run->record())) {
            throw new ConfirmationRequired(ResetRequest::question($request->noBackup)); // S4: decided under the lock
        }
        $project = ComposeProject::forInstance($state, $this->directory);
        if ($run->record()->hasStarted(ResetWayBack::PHASE)) { // killed or exit 8 while going back: finish it, never forward (A2)
            return $this->wayBack->abort($run, $state, $project, ResetWayBack::cause($run));
        }
        $plan = $run->phase('plan', function () use ($run, $state, $project, $digest): array {
            $this->docker->composeUp($project, Installer::INFRASTRUCTURE, RestoreStack::WAIT_SECONDS); // a stopped database is read too
            $subject = ResetSubject::plan($this->app->plan($project), $state, $digest);
            $run->note(ResetSubject::NOTE, $subject->toNote());

            return [...$subject->toPhase(), 'storage_volume' => RestoreStorage::current($state)];
        }, changes: false);
        $subject = ResetSubject::fromJournal($plan, $this->note($run->record()));
        $first = ! self::dropBegan($run->record());
        $failed = $first ? $this->beforeDrop($run, $state, $project, $request) : null;
        $failed ??= $this->drop($run, $state, $project, $first);

        return $failed ?? $this->rebuild->run($run, $state, $project, $request, $subject, $context, $toFile);
    }

    private function beforeDrop(OperationRun $run, InstanceState $state, ComposeProject $project, ResetRequest $request): ?CommandResult
    {
        try {
            $again = $run->record()->hasStarted('quiesce') && $this->writersRunning($project); // written after the ledger line (A1)
            $run->phase('quiesce', function () use ($state, $project, $again): array {
                $this->stack->quiesce($state, $project);
                new RuntimeWriter($this->directory)->raiseGate($state, self::OPERATION); // the gate names the reset

                return ['gate' => 'raised', 'writers_ran' => $again];
            }, always: true);
            $this->interruption->checkpoint();
            $run->phase('ledger', fn (): array => $this->recordLedger($state, $project, $request, $run->record()->id()), always: $again);
            $this->interruption->checkpoint();
            if ($request->noBackup) {
                $run->phase('audit_export', fn (): array => $this->exportIfReal($project, $run->record()), always: $again);
            } else {
                $run->phase('safety_backup', fn (): array => $this->safety->take($state, self::OPERATION, new LockHandle($this->lock->current() ?? throw new InstanceException('The instance lock was lost during the reset.', ExitCode::AbortedUnchanged, 'lock_lost'))), always: $again);
            }
            $this->interruption->checkpoint();
        } catch (Throwable $e) {
            return $this->wayBack->abort($run, $state, $project, $e);
        }

        return null;
    }

    /**
     * The database is dropped and created again, every writer stopped first (A1). Repeated while the new epoch has not
     * begun (the database then holds nothing but the migrations, review M1). When the first attempt fails and the old
     * database still answers, nothing was lost: the journal notes it (A3) and the way back runs.
     */
    private function drop(OperationRun $run, InstanceState $state, ComposeProject $project, bool $first): ?CommandResult
    {
        try {
            $run->phase(self::DROP, function () use ($project): array {
                $this->docker->composeStop($project, RestoreHalt::WRITERS);

                return $this->app->recreate($project);
            }, always: ! $run->record()->hasCompleted('epoch'));
        } catch (Throwable $e) {
            if ($first && $this->untouched($project)) {
                $run->note(self::DROP_NOT_REACHED, ['message' => $e->getMessage()]);

                return $this->wayBack->abort($run, $state, $project, $e);
            }

            return $this->rebuild->stuck($e, $run->record());
        }

        return null;
    }

    /** Whether the old database still answers the plan query (a first, failed drop that never reached it). */
    private function untouched(ComposeProject $project): bool
    {
        try {
            return is_array($this->app->plan($project)->data['organizations'] ?? null);
        } catch (Throwable) {
            return false;
        }
    }

    private function writersRunning(ComposeProject $project): bool
    {
        return array_any(ComposeFile::serviceContainers($this->docker->composePs($project)), static fn (ContainerInfo $container): bool => $container->running() && in_array($container->service(), RestoreHalt::WRITERS, true));
    }

    /** @return array<string, mixed> the audit export, or why there is none (only demo organizations, counted after the quiesce) */
    private function exportIfReal(ComposeProject $project, JournalRecord $record): array
    {
        return ($record->phaseData('ledger')['non_demo'] ?? null) === 0
            ? ['file' => null, 'skipped' => 'only_demo_organizations']
            : $this->app->export($project);
    }

    /**
     * The final heads appended to the ledger (R43, KTD26) and, read once the writers stopped, the old database's sequence
     * values (the new database's floors, review S1) and organization counts (the export decision, review M2).
     *
     * @return array<string, mixed>
     */
    private function recordLedger(InstanceState $state, ComposeProject $project, ResetRequest $request, string $journal): array
    {
        $heads = $this->stack->heads($project);
        $old = $this->app->plan($project)->data;
        $this->ledger->append([
            'action' => self::OPERATION, 'host_uid' => $request->actorUid, 'journal' => $journal, 'version' => $state->version(),
            'demo' => $request->demo, 'no_backup' => $request->noBackup, 'epoch' => $heads['epoch'], 'heads' => (object) $heads['heads'], 'intact' => $heads['intact'],
        ]);
        $counts = is_array($old['organizations'] ?? null) ? $old['organizations'] : [];

        return [
            'seed' => max($this->ledger->seed(), $heads['epoch']), 'epoch' => $heads['epoch'], 'heads' => (object) $heads['heads'], 'intact' => $heads['intact'],
            'sequences' => (object) (is_array($old['sequences'] ?? null) ? $old['sequences'] : []), 'non_demo' => is_int($counts['non_demo'] ?? null) ? $counts['non_demo'] : null,
        ];
    }

    /** @return array<string, mixed> the newest owner note of the journal */
    private function note(JournalRecord $record): array
    {
        $notes = array_values(array_filter($record->events(), static fn (array $event): bool => $event['type'] === ResetSubject::NOTE));

        return $notes === [] ? [] : $notes[array_key_last($notes)]['data'];
    }
}
