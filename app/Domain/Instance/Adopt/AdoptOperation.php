<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\InstallSmoke;
use App\Domain\Instance\Install\InstanceStack;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
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
 * `fsa adopt <checkout>` (R46 as changed by R60, F7, J9, KTD20, plan U19, ADR 0087): a 1.0 install becomes an instance by
 * copying its data, journaled and resumable under the instance lock:
 *
 * doctor (AdoptPreflight; 1.0 keeps running) → the phases before 2.0 starts (AdoptSequence: secrets, state, pull,
 * stop_1_0, backup_1_0, stop_postgres_1_0, copy, verify, up, migrate, baseline; 1.0 must stay stopped, LegacyFence) →
 * start → smoke → done (the ledger's `adopted` line, the `installed` and `adopted` records).
 *
 * Any failure before `start` goes back (AdoptRollback): 1.0 runs again unchanged and nothing of 2.0 is left (exit 5; exit
 * 9 when 1.0 had not been stopped yet; exit 8 when 1.0 does not start again). From `start` on the instance runs on its own
 * copy and a failure leaves the journal open for a resume (exit 4, with an adopt hint); `fsa uninstall` (which treats the
 * unfinished adopt as adopted) and `docker compose up -d` in the checkout lead back. A resumed run continues where the last
 * one stopped, checking first that 1.0 stayed stopped; one that began going back finishes that.
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
            throw new InvalidInput('This adopt already started 2.0 on its copy, which may hold new data: abandoning it would delete that without a backup.', 'adopt_started', 'Run `fsa adopt <checkout>` again to finish it, or `fsa uninstall` (a final backup first) and then `docker compose up -d` in the 1.0 checkout.');
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
        if (! $run->record()->hasStarted('start')) {
            try {
                $this->assertSameKey($preflight, $checkout);
                $data = $this->sequence->run($run, $checkout, $legacy, $preflight, $request, $context);
            } catch (Throwable $e) {
                return $this->undo($run, $e);
            }
        }
        $data ??= AdoptSequence::phaseData($run->record());
        $state = $this->directory->state();
        $project = ComposeProject::forInstance($state, $this->directory);
        $this->resumable($request, fn (): array => $run->phase('start', fn (): array => $this->stack->up($project, []), maxSeconds: InstanceStack::WAIT_SECONDS));
        $smoke = $this->resumable($request, fn (): array => $run->phase('smoke', fn (): array => $this->smoke->run($state, $project, null, $context->progress), changes: false, maxSeconds: InstallSmoke::maxSeconds($state)));
        $run->phase('done', fn (): array => $this->record->done($legacy, $preflight, $data, $request->callerUid, $run->record()->id()));
        $result = AdoptResult::build($this->directory->state(), $this->directory, $legacy, $preflight, [...$data, 'smoke' => $smoke]);
        $running = $this->data->legacyRunning($legacy);

        return $running === [] ? $result : $result->withWarning('legacy_running', '1.0 runs again next to the instance ('.implode(', ', $running).'): what it writes is not in the instance.', "Stop it (cd {$legacy->checkout} && docker compose stop); the instance holds the data adopt copied.");
    }

    /**
     * A resumed run continues with the APP key the first run adopted (review S2): a changed `.env` stops it.
     *
     * @param  array<string, mixed>  $preflight
     *
     * @throws InstanceException exit 7 `env_changed`
     */
    private function assertSameKey(array $preflight, LegacyCheckout $checkout): void
    {
        $adopted = $preflight['app_key_fingerprint'] ?? null;
        $now = AppKeys::of([$checkout->appKey()])->fingerprints()[0] ?? null;
        if (is_string($adopted) && $adopted !== $now) {
            throw new InstanceException('The APP_KEY in the 1.0 .env changed since this adopt began.', ExitCode::Incompatible, 'env_changed', 'Restore the .env 1.0 runs with, or abandon this adopt (`fsa adopt --abandon`) and start over.');
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
            return $phase();
        } catch (InstanceException $e) {
            throw new InstanceException($e->getMessage(), $e->exitCode, $e->errorCode,
                "Find the cause (`fsa logs`), then run `fsa adopt {$request->checkout}` again: it resumes here. To go back to 1.0 instead: `fsa uninstall` (a final backup first), then `docker compose up -d` in {$request->checkout}.",
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
