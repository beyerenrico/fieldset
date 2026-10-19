<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Backup\BackupRunner;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Demo\DemoCredentials;
use App\Domain\Instance\Demo\DemoData;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Instance\Upgrade\SafetySeal;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The second half of `fsa reset` (plan U23, KTD26, ADR 0088), after the database was recreated (ResetOperation); it
 * only goes forward:
 *
 * migrate (again after every recreate, until the epoch began; and the backup service's role, as install does) → epoch
 * (every sequence above the old database's, the new epoch seeded from the ledger, `instance.reset` with the old heads as
 * its first entry, the state's `reset` history entry) → storage (a fresh, empty, labelled volume; the state names it) →
 * flush (Valkey with its queues, until it succeeds; the mail catcher) → seed (install's `app:install`, one transaction:
 * the first organization, its owner, the operator role, the owner's sign-in link) → verify (the app alone behind the
 * gate, the internal smoke test) → open (every service, still behind the gate) → [demo, demo_rotate: `--demo` under the
 * reachability rule of R29] → gate (down) → cleanup (the replaced storage volume and leftovers of earlier restores
 * removed, ResetLeftovers; the safety backup sealed).
 *
 * Every phase is repeatable from the journal: a killed run is continued by the next `fsa reset` with the same flags.
 * Settings and secrets stay; webhooks are not paused (no data, so no run whose deliveries could repeat).
 */
final readonly class ResetRebuild
{
    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private RestoreStack $stack,
        private RestoreStorage $storage,
        private SafetyBackup $safety,
        private BackupRunner $backups,
        private AppCommands $commands,
        private ResetApp $app,
        private CatcherFlush $catcher,
        private DemoData $demo,
        private ResetLeftovers $leftovers,
        private SafetySeal $seals,
    ) {}

    public function run(OperationRun $run, InstanceState $state, ComposeProject $project, ResetRequest $request, ResetSubject $subject, FsaContext $context, bool $toFile): CommandResult
    {
        try {
            return $this->forward($run, $state, $project, $request, $subject, $context, $toFile);
        } catch (Throwable $e) {
            return $this->stuck($e, $run->record());
        }
    }

    /**
     * Encrypts every safety backup of the journal to the recipients once the reset closed (R57, verification V2: also one
     * a retake superseded, and one a killed run wrote before the journal named it, M12 gate C3); a failure is a warning per
     * backup.
     */
    public function seal(CommandResult $result, JournalRecord $record): CommandResult
    {
        $lock = $this->lock->current();
        if (! $lock instanceof LockRecord) {
            return $result;
        }
        $backups = ResetResult::safetyBackups($record);
        foreach ($this->seals->seal(array_values(array_diff($this->seals->since(ResetOperation::OPERATION, $record->startedAt()), $backups))) as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }
        foreach ($backups as $backup) {
            try {
                $this->safety->seal($this->directory->state(), $backup, new LockHandle($lock));
            } catch (Throwable $e) {
                $result = $result->withWarning('safety_backup_not_sealed', "The safety backup {$backup} stays unencrypted (0600): ".$e->getMessage(), 'Encrypt or delete it by hand.');
            }
        }

        return $result;
    }

    private function forward(OperationRun $run, InstanceState $state, ComposeProject $project, ResetRequest $request, ResetSubject $subject, FsaContext $context, bool $toFile): CommandResult
    {
        $record = $run->record();
        $run->phase('migrate', fn (): array => $this->migrate($project), always: ! $record->hasCompleted('epoch')); // after every recreate
        $epoch = $run->phase('epoch', fn (): array => $this->epoch($project, $request, $record));
        $volume = $run->phase('storage', fn (): array => $this->freshStorage($state, $record), changes: true);
        $flushed = $run->phase('flush', fn (): array => $this->flush($project));
        $link = null;
        $run->phase('seed', function () use ($project, $subject, $request, $context, &$link): array {
            $result = $this->commands->install($project, $subject->installRequest(), $request->actorUid);
            if (! $result->ok) {
                throw new InstanceException('`app:install` failed: '.$result->errorMessage(), ExitCode::HealthCheckFailed, 'app_install_failed', null, true, ['code' => $result->errorCode(), 'output' => $result->output]);
            }
            $link = $result->secret(SignInDelivery::SECRET);
            if ($link !== null) {
                $context->redactor->register($link['value']);
            }

            return ['organization' => $subject->organization, 'already_installed' => $result->hasWarning('already_installed')];
        });
        $this->open($run, $project);
        [$demo, $warnings] = $request->demo ? $this->demo->provide($run, $project, $request->actorUid, reissue: true) : [null, []];
        $run->phase('gate', function (): array {
            $this->stack->lowerGate($this->directory->state());

            return ['lowered' => true];
        }, always: true);
        $cleanup = $run->phase('cleanup', fn (): array => $this->leftovers->remove($this->directory->state(), $project, (string) ($volume['volume'] ?? ''), is_string($volume['previous'] ?? null) ? $volume['previous'] : null), always: true);
        $link ??= $this->freshLink($project, $subject, $request, $context, $warnings);
        $result = ResetResult::ok($this->directory->state(), $subject, $run->record(), $epoch, $flushed, $cleanup, $warnings);
        $result = $link === null ? $result : SignInDelivery::deliver($result, $context, $link['value'], $link['expires_at'], $toFile, $subject->ownerEmail);
        $result = $demo instanceof DemoCredentials ? $demo->apply($result, $context, $toFile) : $result;

        return $this->seal($result, $run->record());
    }

    /**
     * The migrations, then the backup service's role (its grant needs `backup_runs`), as install's migrate phase.
     *
     * @return array{migrated: true, backup_role: true}
     */
    private function migrate(ComposeProject $project): array
    {
        $outcome = $this->commands->migrate($project);
        if (! $outcome->successful()) {
            throw new InstanceException('The database migrations failed.', ExitCode::HealthCheckFailed, 'migrate_failed', null, true, ['output' => $outcome->stderrTail(10)]);
        }
        $this->backups->ensureRole($project);

        return ['migrated' => true, 'backup_role' => true];
    }

    /** @return array<string, mixed> */
    private function epoch(ComposeProject $project, ResetRequest $request, JournalRecord $record): array
    {
        $ledger = $record->phaseData('ledger');
        $safety = ResetResult::safetyBackup($record);
        $export = is_string($record->phaseData('audit_export')['file'] ?? null) ? $record->phaseData('audit_export')['file'] : null;
        $previous = is_int($ledger['epoch'] ?? null) ? $ledger['epoch'] : null;
        $input = ['previous_heads' => (object) ($ledger['heads'] ?? []), 'previous_epoch' => $previous, 'sequences' => (object) ($ledger['sequences'] ?? [])]; // review S1
        $data = $this->app->epoch($project, is_int($ledger['seed'] ?? null) ? $ledger['seed'] : 0, $input, $safety, $export, $request->demo, $request->actorUid);
        $state = $this->directory->state();
        if (! array_any($state->history(), static fn (array $entry): bool => ($entry['event'] ?? null) === ResetStatus::EVENT && ($entry['journal'] ?? null) === $record->id())) {
            $this->directory->saveState($state->withHistoryEntry(ResetStatus::EVENT, [
                'journal' => $record->id(), 'epoch' => $data['epoch'] ?? null, 'previous_epoch' => $previous, 'safety_backup' => $safety, 'audit_export' => $export, 'demo' => $request->demo,
            ]));
        }

        return $data;
    }

    /** @return array{volume: string, previous: string} a fresh, empty volume the state names (the old one goes in cleanup) */
    private function freshStorage(InstanceState $state, JournalRecord $record): array
    {
        $previous = is_string($record->phaseData('plan')['storage_volume'] ?? null) ? $record->phaseData('plan')['storage_volume'] : RestoreStorage::current($state);
        $fresh = $state->project().'_'.AppRoles::STORAGE_VOLUME.'-reset-'.substr((string) preg_replace('/[^a-f0-9]/', '', strtolower($record->id())), 0, 12);
        $this->storage->remove($fresh); // what an interrupted attempt left: empty, and nothing mounted it before the verification
        $this->storage->create($state, $fresh);
        $this->stack->apply($this->directory->state()->withSetting('storage_volume', $fresh));

        return ['volume' => $fresh, 'previous' => $previous];
    }

    /** The app alone behind the gate passes the smoke test, then every service starts, still behind it. */
    private function open(OperationRun $run, ComposeProject $project): void
    {
        if ($run->record()->hasStarted('gate')) {
            return; // lowering began: the instance runs
        }
        $state = $this->directory->state();
        $token = new RuntimeWriter($this->directory)->raiseGate($state, ResetOperation::OPERATION);
        $run->phase('verify', fn (): array => ['checked' => $this->stack->startAndVerify($state, $project, $token)], always: true);
        $run->phase('open', function () use ($project): array {
            $this->stack->startAll($project);

            return ['open' => true];
        }, always: true);
    }

    /**
     * Valkey must be empty before anything starts (review S3): the drained queue leaves pending jobs of the old data there,
     * which would run against new rows with the same IDs. A failure keeps the gate up; the next run repeats the flush.
     * The catcher is a warning only (old mail stays visible, nothing runs on it).
     *
     * @return array{valkey: true, catcher: bool|null}
     */
    private function flush(ComposeProject $project): array
    {
        if (! $this->stack->flushValkey($project)) {
            throw new InstanceException('Valkey could not be emptied: queued jobs of the old data would run against new rows with the same IDs.', ExitCode::HealthCheckFailed, 'valkey_not_flushed', 'Read `fsa logs redis`; nothing starts before Valkey is empty', true);
        }

        return ['valkey' => true, 'catcher' => $this->catcher->flush($this->directory->state(), $project)];
    }

    /**
     * A new 15-minute link for the owner when this run did not seed (resumed after `seed`): the first one went with the
     * interrupted run.
     *
     * @param  list<array{0: string, 1: string, 2: string|null}>  $warnings
     * @return array{value: string, expires_at: CarbonImmutable|null}|null
     */
    private function freshLink(ComposeProject $project, ResetSubject $subject, ResetRequest $request, FsaContext $context, array &$warnings): ?array
    {
        $issued = $this->commands->loginLink($project, $subject->ownerEmail, $request->actorUid);
        $link = $issued->ok ? $issued->secret(SignInDelivery::SECRET) : null;
        if ($link === null) {
            $warnings[] = ['sign_in_link_unavailable', 'No sign-in link could be issued: '.$issued->errorMessage(), 'Run '.SignInDelivery::loginLinkCommand($subject->ownerEmail).' once the cause is fixed.'];

            return null;
        }
        $context->redactor->register($link['value']);

        return $link;
    }

    /** From the drop on there is no way back: the journal stays open, the gate up, and `fsa reset` continues. */
    public function stuck(Throwable $cause, JournalRecord $record): CommandResult
    {
        $exit = $cause instanceof InstanceException && ! $cause->exitCode->isBeforeChange() ? $cause->exitCode : ExitCode::HealthCheckFailed;
        $kept = ResetResult::safetyBackup($record) ?? (is_string($record->phaseData('audit_export')['file'] ?? null) ? $record->phaseData('audit_export')['file'] : null);

        return CommandResult::failed(ResetOperation::OPERATION, $exit, $cause instanceof InstanceException ? $cause->errorCode : 'reset_failed',
            'The reset stopped after it began replacing the database: '.$cause->getMessage(),
            'Fix the cause'.($cause instanceof InstanceException && $cause->hint !== null ? ' ('.$cause->hint.')' : '').', then run `'.ResetRequest::commandLine($record->context()).'` again: it continues from here. The old data '.($kept === null ? 'was not backed up.' : "is in backups/{$kept}."),
            true, [...($cause instanceof InstanceException ? $cause->data : []), 'safety_backup' => ResetResult::safetyBackup($record)], changed: true);
    }
}
