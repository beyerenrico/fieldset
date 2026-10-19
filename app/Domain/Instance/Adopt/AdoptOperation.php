<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Backup\BackupRunner;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\FromBackupKeys;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\InstallSmoke;
use App\Domain\Instance\Install\InstanceStack;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\ComposeFile;
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
use Throwable;

/**
 * `fsa adopt <checkout>` (R46 as changed by R60, F7, J9, KTD20, plan U19, ADR 0087): a 1.0 install becomes an instance by
 * copying its data, journaled and resumable under the instance lock:
 *
 * doctor (AdoptPreflight; 1.0 keeps running) → secrets (1.0's APP keys) → state (the inferred mode, 1.0's database names
 * and sender, capture mail) → pull → stop_1_0 (app and scheduler, drain, queue) → backup_1_0 (1.0 format) →
 * stop_postgres_1_0 → copy (new labelled volumes) → verify (counts, chain heads, amcheck) → up → migrate (and the backup
 * role) → start → smoke → done (baseline snapshot in the ledger, the `installed` and `adopted` records).
 *
 * Any failure before `start` goes back (AdoptRollback): 1.0 runs again unchanged and nothing of 2.0 is left (exit 5; exit
 * 9 when 1.0 had not been stopped yet; exit 8 when 1.0 does not start again). From `start` on the instance runs on its own
 * copy and a failure leaves the journal open for a resume (exit 4); `fsa uninstall` and `docker compose up -d` in the
 * checkout lead back. A resumed run continues where the last one stopped; one that began going back finishes that.
 */
final readonly class AdoptOperation
{
    public const string OPERATION = 'adopt';

    public const string EVENT = 'adopted';

    /** The first phase that touches 1.0. */
    private const string QUIESCE = 'stop_1_0';

    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private AdoptPreflight $preflight,
        private FromBackupKeys $keys,
        private InstanceStack $stack,
        private ComposeRenderer $renderer,
        private AdoptData $data,
        private AppCommands $app,
        private BackupRunner $backups,
        private InstallSmoke $smoke,
        private AdoptRollback $rollback,
        private AdoptRecord $record,
    ) {}

    public function adopt(AdoptRequest $request, FsaContext $context): CommandResult
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if ($state instanceof InstanceState && InstallRecord::isInstalled($state)) {
            return AdoptResult::unchanged($state, $this->directory, $request);
        }

        return $this->supervisor->run(
            self::OPERATION, $this->identity->holder('fsa', $request->callerUid),
            fn (OperationRun $run): CommandResult => $this->steps($run, $request, $context),
            resume: true, context: $request->toContext(), progress: $context->progress,
            accepts: static fn (JournalRecord $record): bool => ($record->context()['checkout'] ?? null) === $request->checkout,
        );
    }

    /**
     * `fsa adopt --abandon`: an adopt that stopped before 2.0 started goes back like a failed one (1.0 starts again, the
     * new instance's resources and files go), also after an exit 8 once 1.0 can start. Once 2.0 started it may hold
     * writes of its own: then only `fsa uninstall` (a final backup first) leads back (exit 2 `adopt_started`).
     *
     * @throws InvalidInput exit 2 without an unfinished adopt, or once 2.0 started
     */
    public function abandon(FsaContext $context): CommandResult
    {
        $current = $this->journal->current();
        if (! $current instanceof JournalRecord || $current->operation() !== self::OPERATION) {
            throw new InvalidInput('No unfinished adopt to abandon here.', 'nothing_to_abandon', 'An adopt that finished is undone with `fsa uninstall` and `docker compose up -d` in the 1.0 checkout.');
        }
        if ($current->hasStarted('start')) {
            throw new InvalidInput('This adopt already started 2.0 on its copy, which may hold new data: abandoning it would delete that without a backup.', 'adopt_started', 'Run `fsa adopt <checkout>` again to finish it, or `fsa uninstall --keep-backups` (a final backup first) and then `docker compose up -d` in the 1.0 checkout.');
        }

        return $this->supervisor->run(self::OPERATION, $this->identity->holder('fsa', $context->facts->callerUid()),
            fn (OperationRun $run): CommandResult => $this->undo($run, null), resume: true, progress: $context->progress);
    }

    private function steps(OperationRun $run, AdoptRequest $request, FsaContext $context): CommandResult
    {
        if (array_any($run->record()->events(), static fn (array $event): bool => $event['type'] === 'rollback')) {
            return $this->undo($run, new InstanceException('An earlier attempt began going back.', ExitCode::RolledBack, 'adopt_interrupted', 'Run `fsa adopt` again to start over.'));
        }
        $checkout = LegacyCheckout::read($request->checkout);
        $preflight = $run->phase('doctor', fn (): array => $this->preflight->run($checkout, $request->release, $context->facts), changes: false);
        $legacy = LegacyInstall::fromArray(is_array($preflight['legacy'] ?? null) ? $preflight['legacy'] : []);
        if (! $run->record()->hasCompleted('migrate')) {
            try {
                $data = $this->prepare($run, $checkout, $legacy, $preflight, $request, $context);
            } catch (Throwable $e) {
                return $this->undo($run, $e);
            }
        }
        $data ??= $this->phaseData($run->record());
        $state = $this->directory->state();
        $project = ComposeProject::forInstance($state, $this->directory);
        $run->phase('start', fn (): array => $this->stack->up($project, []), maxSeconds: InstanceStack::WAIT_SECONDS);
        $smoke = $run->phase('smoke', fn (): array => $this->smoke->run($state, $project, null, $context->progress), changes: false, maxSeconds: InstallSmoke::maxSeconds($state));
        $run->phase('done', fn (): array => $this->record->done($state, $project, $legacy, $preflight, $data, $request->callerUid));

        return AdoptResult::build($this->directory->state(), $this->directory, $legacy, $preflight, [...$data, 'smoke' => $smoke]);
    }

    /**
     * Everything before 2.0 starts: on failure the caller goes back.
     *
     * @param  array<string, mixed>  $preflight
     * @return array<string, mixed>
     */
    private function prepare(OperationRun $run, LegacyCheckout $checkout, LegacyInstall $legacy, array $preflight, AdoptRequest $request, FsaContext $context): array
    {
        $run->phase('secrets', fn (): array => $this->keys->ensureKeys(static fn (): array => [$checkout->appKey(), $checkout->previousKeys()], $context->redactor));
        $run->phase('state', fn (): array => $this->writeState($preflight, $context->facts));
        $state = $this->directory->state();
        $project = ComposeProject::forInstance($state, $this->directory);
        $run->phase('pull', fn (): array => $this->stack->pull(), changes: false);
        $run->phase(self::QUIESCE, fn (): array => $this->data->quiesce($state, $legacy, $request->dropJobs), maxSeconds: QueueDrain::SECONDS + 300);
        $run->phase('backup_1_0', fn (): array => $this->data->backup($state, $legacy, $checkout));
        $run->phase('stop_postgres_1_0', fn (): array => $this->data->stopData($legacy));
        $run->phase('copy', fn (): array => $this->data->copy($state, $project, $legacy));
        $backup = $run->record()->phaseData('backup_1_0');
        $run->phase('verify', fn (): array => $this->data->verify($state, $project, $legacy, DataSnapshot::fromArray(is_array($backup['snapshot'] ?? null) ? $backup['snapshot'] : [])));
        $run->phase('up', fn (): array => [...$this->stack->up($project, Installer::INFRASTRUCTURE), ...$this->stack->trustGateway($project, $preflight)]);
        $run->phase('migrate', fn (): array => $this->migrate($project));

        return $this->phaseData($run->record());
    }

    /**
     * The way back (AdoptRollback), journaled first so a killed run finishes it on its next start.
     */
    private function undo(OperationRun $run, ?Throwable $cause): CommandResult
    {
        $record = $run->record();
        $doctor = $record->phaseData('doctor');
        $legacy = LegacyInstall::fromArray(is_array($doctor['legacy'] ?? null) ? $doctor['legacy'] : []);
        if ($legacy->project === '' || $legacy->containers === []) {
            throw $cause ?? new InvalidInput('The unfinished adopt never found its 1.0 install; nothing to undo.', 'nothing_to_abandon');
        }
        $touched = $record->hasStarted(self::QUIESCE);
        $run->note('rollback', ['cause' => $cause instanceof InstanceException ? $cause->errorCode : ($cause instanceof Throwable ? 'internal_error' : 'abandoned')]);
        $undo = $this->rollback->undo($legacy, is_string($doctor['uuid'] ?? null) ? $doctor['uuid'] : null);
        $run->note('rolled_back', $undo);
        if (! $touched) {
            $run->markReverted($undo);
        }
        if (! $cause instanceof Throwable) {
            return AdoptResult::undoWarnings(CommandResult::ok(self::OPERATION, ['abandoned' => true, 'rollback' => $undo, 'checkout' => $legacy->checkout], changed: true), $undo, $legacy);
        }

        return AdoptResult::rolledBack($cause, $touched, $undo, $legacy);
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array{project: string, services: list<string>}
     */
    private function writeState(array $preflight, HostFacts $facts): array
    {
        $this->stack->writeState($preflight, $facts);
        /** @var array<string, string> $settings */
        $settings = is_array($preflight['settings'] ?? null) ? $preflight['settings'] : [];
        $state = $this->directory->state()->withSettings($settings);
        $this->directory->saveState($state);
        $secrets = $this->stack->secrets();
        $rendered = $this->renderer->write($this->directory, $state, $secrets, ComposeFile::emulated($this->directory, $this->renderer, $state, $secrets));

        return ['project' => $state->project(), 'services' => $rendered->services()];
    }

    /** @return array{migrated: true, backup_role: true} */
    private function migrate(ComposeProject $project): array
    {
        $outcome = $this->app->migrate($project);
        if (! $outcome->successful()) {
            $output = implode("\n", array_slice(preg_split('/\R/', trim($outcome->stdout)) ?: [], -10));
            throw new InstanceException('The migrations of the copied 1.0 database failed.', ExitCode::HealthCheckFailed, 'migrate_failed', 'Read the output; 1.0 runs again unchanged. Fix the cause and run adopt again.', true, ['output' => trim($output."\n".$outcome->stderrTail(10))]);
        }
        $this->backups->ensureRole($project);

        return ['migrated' => true, 'backup_role' => true];
    }

    /** @return array<string, mixed> the data phases' results (also on a resume that skips them) */
    private function phaseData(JournalRecord $record): array
    {
        return ['quiesce' => $record->phaseData(self::QUIESCE), 'backup' => $record->phaseData('backup_1_0'), 'copy' => $record->phaseData('copy'), 'verify' => $record->phaseData('verify')];
    }
}
