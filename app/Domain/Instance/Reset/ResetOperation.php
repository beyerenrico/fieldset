<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\RecordDigest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Throwable;

/**
 * `fsa reset` (§7, R43, R57, R68, KTD26, plan U23, ADR 0088) under the instance lock, journaled phase by phase. Up to the
 * drop nothing is lost:
 *
 * plan (the live database read: owner, organization, whether an audit export is needed; exit 3 when no owner exists) →
 * quiesce (like a restore: gate up, writers stopped, the queue drained, pending audit entries sealed) → ledger (the final
 * chain heads, before the database goes) → safety_backup (unless `--no-backup`) or audit_export (`--no-backup` while
 * real organizations exist) → … ResetRebuild from `recreate_database` on.
 *
 * A failure (or a signal, at a phase boundary) before the drop starts the previous stack again behind the gate, which
 * goes down last: exit 5 `rolled_back`, nothing deleted. From the drop on the reset only goes forward: a failure leaves
 * the journal open and the gate up, and the next `fsa reset` with the same flags continues without asking (`fsa status`
 * shows it).
 */
final readonly class ResetOperation
{
    public const string OPERATION = 'reset';

    /** The first phase that deletes: a reset that began it is only ever finished. */
    public const string DROP = 'recreate_database';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private InstanceLock $lock,
        private DockerGateway $docker,
        private RestoreStack $stack,
        private SafetyBackup $safety,
        private Ledger $ledger,
        private ResetApp $app,
        private ResetRebuild $rebuild,
        private Interruption $interruption,
    ) {}

    public function reset(ResetRequest $request, FsaContext $context, bool $secretsToFile): CommandResult
    {
        $state = RestoreOperation::installed($this->directory);
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));

        return $this->interruption->guard(fn (): CommandResult => $this->supervisor->run(
            self::OPERATION, $this->identity->holder('fsa', $request->actorUid),
            fn (OperationRun $run): CommandResult => $this->steps($run, $state, $request, $context, $secretsToFile, $digest),
            resume: true, context: $request->toContext(), progress: $context->progress,
            archive: static fn (JournalRecord $record): JournalRecord => $record->withValuesReplaced(ResetSubject::PERSONAL, static fn (string $value): ?string => $digest?->digest($value)),
            accepts: static fn (JournalRecord $record): bool => $request->sameAs($record->context()),
        ));
    }

    /** Whether an open reset journal already began deleting (its resume asks nothing). */
    public static function pastDrop(?JournalRecord $open): bool
    {
        return $open?->operation() === self::OPERATION && $open->hasStarted(self::DROP);
    }

    private function steps(OperationRun $run, InstanceState $state, ResetRequest $request, FsaContext $context, bool $toFile, ?RecordDigest $digest): CommandResult
    {
        $project = ComposeProject::forInstance($state, $this->directory);
        $plan = $run->phase('plan', function () use ($run, $state, $project, $request, $digest): array {
            $this->docker->composeUp($project, Installer::INFRASTRUCTURE, RestoreStack::WAIT_SECONDS); // a stopped database is read too
            $subject = ResetSubject::plan($this->app->plan($project), $state, $digest);
            $run->note(ResetSubject::NOTE, $subject->toNote());

            return [...$subject->toPhase(), 'export' => $subject->exportNeeded($request->noBackup), 'storage_volume' => RestoreStorage::current($state)];
        }, changes: false);
        $subject = ResetSubject::fromJournal($plan, $this->note($run->record()));
        if (! $run->record()->hasStarted(self::DROP)) {
            $failed = $this->beforeDrop($run, $state, $project, $request, ($plan['export'] ?? false) === true);
            if ($failed instanceof CommandResult) {
                return $failed;
            }
        }

        return $this->rebuild->run($run, $state, $project, $request, $subject, $context, $toFile);
    }

    private function beforeDrop(OperationRun $run, InstanceState $state, ComposeProject $project, ResetRequest $request, bool $export): ?CommandResult
    {
        try {
            $run->phase('quiesce', function () use ($state, $project): array {
                $this->stack->quiesce($state, $project);
                new RuntimeWriter($this->directory)->raiseGate($state, self::OPERATION); // the gate names the reset

                return ['gate' => 'raised'];
            }, always: true);
            $this->interruption->checkpoint();
            $run->phase('ledger', fn (): array => $this->recordLedger($state, $project, $request, $run->record()->id()));
            $this->interruption->checkpoint();
            if (! $request->noBackup) {
                $run->phase('safety_backup', fn (): array => $this->safety->take($state, self::OPERATION, new LockHandle($this->lock->current() ?? throw new InstanceException('The instance lock was lost during the reset.', ExitCode::AbortedUnchanged, 'lock_lost'))));
            } elseif ($export) {
                $run->phase('audit_export', fn (): array => $this->app->export($project));
            }
            $this->interruption->checkpoint();
        } catch (Throwable $e) {
            return $this->abort($state, $project, $e, $run->record());
        }

        return null;
    }

    /** @return array<string, mixed> the final heads appended to the ledger (R43, KTD26) and the seed of the new epoch */
    private function recordLedger(InstanceState $state, ComposeProject $project, ResetRequest $request, string $journal): array
    {
        $heads = $this->stack->heads($project);
        $this->ledger->append([
            'action' => self::OPERATION, 'host_uid' => $request->actorUid, 'journal' => $journal, 'version' => $state->version(),
            'demo' => $request->demo, 'no_backup' => $request->noBackup, 'epoch' => $heads['epoch'], 'heads' => (object) $heads['heads'], 'intact' => $heads['intact'],
        ]);

        return ['seed' => max($this->ledger->seed(), $heads['epoch']), 'epoch' => $heads['epoch'], 'heads' => (object) $heads['heads'], 'intact' => $heads['intact']];
    }

    /**
     * Before the drop: the previous stack starts again behind the gate, which goes down last (exit 5, nothing deleted).
     * When even that fails, everything stays stopped behind the gate and the journal open: exit 8.
     */
    private function abort(InstanceState $state, ComposeProject $project, Throwable $cause, JournalRecord $record): CommandResult
    {
        $safety = $record->phaseData('safety_backup')['backup'] ?? null;
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'reset_failed';
        $data = [...($cause instanceof InstanceException ? $cause->data : []), 'safety_backup' => is_string($safety) ? $safety : null];

        try {
            $this->stack->reopen($state, $project);
        } catch (Throwable $e) {
            return CommandResult::failed(self::OPERATION, ExitCode::RollbackFailed, 'reset_rollback_failed',
                'The reset failed before anything was deleted ('.$cause->getMessage().'), and starting the instance again failed too ('.$e->getMessage().'); it is stopped behind its gate.',
                'Run `'.ResetRequest::commandLine($record->context()).' --yes` again to finish the reset, once the cause is fixed (`fsa logs app`).', true, $data, changed: true);
        }
        $result = CommandResult::failed(self::OPERATION, ExitCode::RolledBack, $code, 'The reset failed before anything was deleted; the instance runs as before. Cause: '.$cause->getMessage(),
            $cause instanceof InstanceException && $cause->hint !== null ? $cause->hint : 'Fix the cause, then run `fsa reset` again.', true, $data, changed: true);

        return is_string($safety) ? $this->rebuild->seal($result, $safety) : $result;
    }

    /** @return array<string, mixed> the newest owner note of the journal */
    private function note(JournalRecord $record): array
    {
        $notes = array_values(array_filter($record->events(), static fn (array $event): bool => $event['type'] === ResetSubject::NOTE));

        return $notes === [] ? [] : $notes[array_key_last($notes)]['data'];
    }
}
