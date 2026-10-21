<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\InstallSmoke;
use App\Domain\Instance\Install\InstanceStack;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Closure;
use Throwable;

/**
 * `fieldset adopt <checkout>` (R46 as changed by R60, F7, J9, KTD20, plan U19, ADR 0087): a 1.0 install becomes an instance by
 * copying its data, journaled and resumable under the instance lock:
 *
 * doctor (AdoptPreflight; 1.0 keeps running) → the phases before 2.0 starts (AdoptSequence: secrets, state, pull,
 * stop_1_0, backup_1_0, stop_postgres_1_0, copy, verify, up, migrate, baseline; 1.0 must stay stopped, LegacyFence) →
 * start → smoke → done (the ledger's `adopted` line, the `installed` and `adopted` records).
 *
 * Any failure before `start` goes back (AdoptRollback): 1.0 runs again unchanged and nothing of 2.0 is left (exit 5; exit
 * 9 when 1.0 had not been stopped yet; exit 8 when 1.0 does not start again). From `start` on the instance runs on its own
 * copy and a failure leaves the journal open for a resume (exit 4, with an adopt hint); `fieldset uninstall` (which treats the
 * unfinished adopt as adopted) and `docker compose up -d` in the checkout lead back. 1.0 must still be stopped before and
 * right after `start` on every run (verification review V1, A1), else exit 4 `legacy_restarted` without going back. A
 * resumed run continues where the last one stopped; one that began going back finishes that.
 *
 * SIGTERM, SIGINT and SIGHUP are recorded and handled at the next phase boundary (Interruption, M12 gate O8): before
 * `start` like a failure (the way back), from `start` on by stopping with the journal open for a resume (exit 9
 * `interrupted`, the adopt hint). Hints name the checkout and `--yes` (O9).
 */
final readonly class AdoptOperation
{
    public const string OPERATION = 'adopt';

    public const string EVENT = 'adopted';

    /** The first phase that touches 1.0. */
    private const string QUIESCE = AdoptSequence::QUIESCE;

    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private AdoptPreflight $preflight,
        private AdoptSequence $sequence,
        private InstanceStack $stack,
        private AdoptData $data,
        private InstallSmoke $smoke,
        private AdoptRollback $rollback,
        private AdoptRecord $record,
        private Interruption $interruption,
    ) {}

    public function adopt(AdoptRequest $request, FieldsetContext $context): CommandResult
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if ($state instanceof InstanceState && InstallRecord::isInstalled($state)) {
            return AdoptResult::unchanged($state, $this->directory, $request);
        }

        return $this->interruption->guard(fn (): CommandResult => $this->supervisor->run(
            self::OPERATION, $this->identity->holder('fieldset', $request->callerUid),
            fn (OperationRun $run): CommandResult => $this->steps($run, $request, $context),
            resume: true, context: $request->toContext(), progress: $context->progress,
            accepts: static fn (JournalRecord $record): bool => ($record->context()['checkout'] ?? null) === $request->checkout,
        ));
    }

    /**
     * `fieldset adopt --abandon`: an adopt that stopped before 2.0 started goes back like a failed one (1.0 starts again, the
     * new instance's resources and files go), also after an exit 8 once 1.0 can start. Once 2.0 started it may hold
     * writes of its own: then only `fieldset uninstall` (a final backup first) leads back (exit 2 `adopt_started`).
     *
     * @throws InvalidInput exit 2 without an unfinished adopt, or once 2.0 started
     */
    public function abandon(FieldsetContext $context): CommandResult
    {
        $current = $this->journal->current();
        if (! $current instanceof JournalRecord || $current->operation() !== self::OPERATION) {
            throw new InvalidInput('No unfinished adopt to abandon here.', 'nothing_to_abandon', 'An adopt that finished is undone with `fieldset uninstall` and `docker compose up -d` in the 1.0 checkout.');
        }
        if ($current->hasStarted('start')) {
            throw new InvalidInput('This adopt already started 2.0 on its copy, which may hold new data: abandoning it would delete that without a backup.', 'adopt_started', 'Run `'.OperationUnfinished::commandLine($current).'` again to finish it, or `fieldset uninstall` (a final backup first) and then `docker compose up -d` in the 1.0 checkout.');
        }

        return $this->supervisor->run(self::OPERATION, $this->identity->holder('fieldset', $context->facts->callerUid()),
            fn (OperationRun $run): CommandResult => $this->undo($run, null), resume: true, progress: $context->progress);
    }

    private function steps(OperationRun $run, AdoptRequest $request, FieldsetContext $context): CommandResult
    {
        if (array_any($run->record()->events(), static fn (array $event): bool => $event['type'] === 'rollback')) {
            return $this->undo($run, new InstanceException('An earlier attempt began going back.', ExitCode::RolledBack, 'adopt_interrupted', "Run `fieldset adopt {$request->checkout} --yes` again to start over."));
        }
        if (! $run->record()->hasStarted('start')) {
            try {
                // On a resume the checkout is read again: a changed one goes back too (review V4).
                $checkout = LegacyCheckout::read($request->checkout);
                $preflight = $run->phase('doctor', fn (): array => $this->preflight->run($checkout, $request->release, $context->facts), changes: false);
                $legacy = $this->legacy($preflight);
                $this->assertSameKeys($preflight, $checkout);
                $data = $this->sequence->run($run, $checkout, $legacy, $preflight, $request, $context);
            } catch (Throwable $e) {
                return $this->undo($run, $e);
            }
        } else {
            // Past `start` the checkout is not read again: 2.0 runs on its copy, and the fence is checked first (review V1).
            $preflight = $run->record()->phaseData('doctor');
            $legacy = $this->legacy($preflight);
            $data = AdoptSequence::phaseData($run->record());
            $this->stillStopped($run, $legacy, $request);
        }
        $state = $this->directory->state();
        $project = ComposeProject::forInstance($state, $this->directory);
        $this->resumable($request, fn (): array => $run->phase('start', fn (): array => $this->stack->up($project, []), maxSeconds: InstanceStack::WAIT_SECONDS));
        $this->stillStopped($run, $legacy, $request); // 1.0 may have started between the last check and 2.0's start (review A1)
        $smoke = $this->resumable($request, fn (): array => $run->phase('smoke', fn (): array => $this->smoke->run($state, $project, null, $context->progress), changes: false, maxSeconds: InstallSmoke::maxSeconds($state)));
        $this->resumable($request, static fn (): array => []); // the boundary before `done`: a signal stops here (O8)
        $run->phase('done', fn (): array => $this->record->done($legacy, $preflight, $data, $request->callerUid, $run->record()->id()));
        $result = AdoptResult::build($this->directory->state(), $this->directory, $legacy, $preflight, [...$data, 'smoke' => $smoke]);
        $running = $this->data->legacyRunning($legacy);

        return $running === [] ? $result : $result->withWarning('legacy_running', '1.0 runs again next to the instance ('.implode(', ', $running).'): what it writes is not in the instance.', "Stop it (cd {$legacy->checkout} && docker compose stop); the instance holds the data adopt copied.");
    }

    /** @param  array<string, mixed>  $preflight */
    private function legacy(array $preflight): LegacyInstall
    {
        return LegacyInstall::fromArray(is_array($preflight['legacy'] ?? null) ? $preflight['legacy'] : []);
    }

    /**
     * A resumed run continues with the APP keys the first run adopted (review S2, V5): a changed APP_KEY or
     * APP_PREVIOUS_KEYS in `.env` stops it.
     *
     * @param  array<string, mixed>  $preflight
     *
     * @throws InstanceException exit 7 `env_changed`
     */
    private function assertSameKeys(array $preflight, LegacyCheckout $checkout): void
    {
        $adopted = $preflight['app_key_fingerprints'] ?? null;
        if (is_array($adopted) && $adopted !== AppKeys::of([$checkout->appKey(), ...$checkout->previousKeys()])->fingerprints()) {
            throw new InstanceException('The APP keys in the 1.0 .env changed since this adopt began.', ExitCode::Incompatible, 'env_changed', 'Restore the .env 1.0 runs with, or abandon this adopt (`fieldset adopt --abandon --yes`) and start over.');
        }
    }

    /**
     * From `start` on, 1.0 must still be as adopt left it (R46, review V1, A1): else the instance's copy misses what 1.0
     * wrote meanwhile. Nothing goes back (2.0 may hold writes of its own); the journal stays open.
     *
     * @throws InstanceException exit 4 `legacy_restarted`
     */
    private function stillStopped(OperationRun $run, LegacyInstall $legacy, AdoptRequest $request): void
    {
        try {
            $this->sequence->assertStopped($run, $this->directory->state(), $legacy);
        } catch (InstanceException $e) {
            throw new InstanceException($e->getMessage(), $e->exitCode, $e->errorCode,
                "To keep 1.0: `fieldset uninstall` (a final backup first), then `docker compose up -d` in {$request->checkout}. To adopt its current data: `fieldset uninstall`, stop 1.0 (`docker compose stop` there), then `fieldset adopt {$request->checkout} --yes` again.",
                false, $e->data, $e, true);
        }
    }

    /**
     * From `start` on, a failure keeps the journal open: its hint names the resume and the way back (review A3).
     *
     * @param  Closure(): array<string, mixed>  $phase
     * @return array<string, mixed>
     */
    private function resumable(AdoptRequest $request, Closure $phase): array
    {
        try {
            $this->interruption->checkpoint(); // a signal stops at this boundary, the journal open (O8)

            return $phase();
        } catch (InstanceException $e) {
            throw new InstanceException($e->getMessage(), $e->exitCode, $e->errorCode,
                "Find the cause (`fieldset logs`), then run `fieldset adopt {$request->checkout} --yes` again: it resumes here. To go back to 1.0 instead: `fieldset uninstall` (a final backup first), then `docker compose up -d` in {$request->checkout}.",
                $e->retryable, $e->data, $e, true);
        }
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
}
