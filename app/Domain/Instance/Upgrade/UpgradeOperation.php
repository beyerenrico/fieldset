<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Install\SmokeTest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Throwable;

/**
 * `fsa upgrade` as the detached supervisor runs it (R33–R36, R62, R69, KTD5, KTD19, plan U18, ADR 0086), under the
 * instance lock with its journal (UpgradeJournal): release → doctor → pull (nothing changed: exit 2, 3, 7, 9, 10 or
 * a no-op) → quiesce → safety_backup (a failure: `revert`, exit 9, the previous services running) → migrate → start →
 * smoke (a failure: UpgradeRollback, exit 5, or exit 8 when that fails too) → commit → open (a failure: exit 4, reported
 * and finished by the next `fsa upgrade`, never rolled back).
 *
 * A resumed run decides from the journal: a rollback that began is finished; a committed upgrade only opens; an upgrade
 * killed after its safety backup rolls back (exit 5 `upgrade_interrupted`); one killed while quiescing brings the
 * previous services back (exit 9). `fsa upgrade` without `--resume` takes over a committed journal too: the next call
 * finishes opening.
 */
final readonly class UpgradeOperation
{
    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private DockerGateway $docker,
        private UpgradePreflight $preflight,
        private UpgradeQuiesce $quiesce,
        private UpgradeBackup $backup,
        private UpgradeChild $child,
        private StagedRelease $staged,
        private SmokeTest $smoke,
        private UpgradeFinish $finish,
        private UpgradeRollback $rollback,
    ) {}

    public function run(UpgradeRequest $request, HostFacts $facts, ProgressReporter $progress): CommandResult
    {
        RestoreOperation::installed($this->directory);

        return $this->supervisor->run(
            UpgradeJournal::OPERATION, $this->identity->holder('fsa', $request->actorUid),
            fn (OperationRun $run): CommandResult => $this->steps($run, $request, $facts, $progress),
            resume: true, context: $request->toContext(), progress: $progress,
            accepts: fn (JournalRecord $record): bool => $request->resume || UpgradeJournal::committed($record, $this->directory->state()),
        );
    }

    private function steps(OperationRun $run, UpgradeRequest $request, HostFacts $facts, ProgressReporter $progress): CommandResult
    {
        $state = $this->directory->state();
        $record = $run->record();
        $project = ComposeProject::forInstance($state, $this->directory);
        if (UpgradeJournal::rollingBack($record)) {
            return $this->rollback->rollback($run, $state, $project, UpgradeRollback::cause($run), $request->actorUid);
        }
        if (UpgradeJournal::committed($record, $state)) {
            return $this->opening($run, $state, $project);
        }
        $interrupted = new InstanceException('An earlier run of this upgrade stopped before it committed.', ExitCode::RolledBack, 'upgrade_interrupted', 'Run `fsa upgrade` again.', true);
        if (UpgradeJournal::pastSafetyBackup($record)) {
            return $this->rollback->rollback($run, $state, $project, $interrupted, $request->actorUid);
        }
        if ($record->hasStarted('quiesce')) {
            return $this->revert($run, $state, $project, $interrupted);
        }

        return $this->forward($run, $state, $project, $request, $facts, $progress);
    }

    private function forward(OperationRun $run, InstanceState $state, ComposeProject $project, UpgradeRequest $request, HostFacts $facts, ProgressReporter $progress): CommandResult
    {
        $release = $run->phase('release', fn (): array => $this->preflight->release($state, $facts, $request), changes: false);
        if (($release['current'] ?? false) === true) {
            return CommandResult::ok('upgrade', ['from' => $release['from'], 'to' => $release['version'], 'current' => true]);
        }
        $run->phase('doctor', function () use ($state, $facts, $release): array {
            $this->preflight->assertBackupService();

            return $this->preflight->doctor($state, $facts, $release);
        }, changes: false);
        $run->phase('pull', fn (): array => $this->preflight->pull($release), changes: false);
        $id = UpgradeJournal::shortId($run->record());
        try {
            $run->phase('quiesce', fn (): array => $this->quiesce->quiesce($state, $project, $request, $id), maxSeconds: QueueDrain::DRAIN_SECONDS + 200);
            $backup = $run->phase('safety_backup', fn (): array => $this->backup->take($state, $id, $request->actorUid));
        } catch (Throwable $e) {
            return $this->revert($run, $state, $project, $e);
        }
        try {
            $run->phase('migrate', fn (): array => $this->child->run($state, $project, $release, $id), maxSeconds: UpgradeChild::MAX_SECONDS);
            $run->phase('start', fn (): array => $this->start($state, $project, $release, $id), maxSeconds: UpgradeFinish::WAIT_SECONDS);
            $run->phase('smoke', fn (): array => $this->check($state, $project, $release, $progress));
        } catch (Throwable $e) {
            return $this->rollback->rollback($run, $state, $project, $e, $request->actorUid);
        }
        $run->phase('commit', fn (): array => $this->finish->commit($state, $release, $backup, $run->record()->id()));

        return $this->opening($run, $this->directory->state(), $project);
    }

    /** A failure before the safety backup completed: the previous services again, the journal archived (exit 9, R69). */
    private function revert(OperationRun $run, InstanceState $state, ComposeProject $project, Throwable $cause): CommandResult
    {
        $run->phase('revert', function () use ($state, $project): array {
            $this->quiesce->revert($state, $project);

            return ['reverted' => true];
        }, ['code' => $cause instanceof InstanceException ? $cause->errorCode : 'upgrade_failed', 'message' => $cause->getMessage()]);
        $run->markReverted();
        $e = $cause instanceof InstanceException ? $cause : new InstanceException($cause->getMessage(), ExitCode::AbortedUnchanged, 'upgrade_failed', null, true, [], $cause);

        return CommandResult::fromException('upgrade', $e)->withExitCode(ExitCode::AbortedUnchanged)->withData(['running' => $state->version()]);
    }

    /**
     * KTD19 step 7: the staged files validated and made live, then the app and every changed service but queue,
     * scheduler, backup, catcher and tunnel, behind the gate.
     *
     * @param  array<string, mixed>  $release
     * @return array{services: list<string>}
     */
    private function start(InstanceState $state, ComposeProject $project, array $release, string $id): array
    {
        $stage = $this->child->stage($id);
        $this->staged->validate($state, $stage, UpgradeJournal::strings($release['digests'] ?? null));
        $services = $this->staged->install($stage);
        try {
            $this->docker->composeUp($project, $services, UpgradeFinish::WAIT_SECONDS);
        } catch (DockerCommandFailed $e) {
            throw new InstanceException('The target release did not become healthy behind the gate: '.$e->outcome->stderrTail(2), ExitCode::RolledBack, 'target_unhealthy', null, true, ['logs' => $this->logs($project, $services)], $e);
        }

        return ['services' => $services];
    }

    /**
     * KTD19 step 8: the smoke test behind the gate with a fresh token (through the edge in domain mode), and no index left
     * invalid by a migration.
     *
     * @param  array<string, mixed>  $release
     * @return array{checked: list<string>}
     */
    private function check(InstanceState $state, ComposeProject $project, array $release, ProgressReporter $progress): array
    {
        $target = $state->withRelease((string) $release['version'], UpgradeJournal::strings($release['digests'] ?? null));
        $token = new RuntimeWriter($this->directory)->raiseGate($target, UpgradeJournal::OPERATION);
        try {
            $checked = $this->smoke->gated($target, $project, $token, $progress);
        } catch (InstanceException $e) {
            throw new InstanceException($e->getMessage(), ExitCode::RolledBack, $e->errorCode, null, true, ['logs' => $this->logs($project, ['app']), ...$e->data], $e);
        }
        $indexes = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:upgrade-db', 'invalid-indexes', '--json'], timeout: 300.0));
        $invalid = UpgradeJournal::list($indexes->data['indexes'] ?? null);
        if (! $indexes->ok || $invalid !== []) {
            throw new InstanceException($indexes->ok ? 'Indexes are left invalid after the migrations: '.implode(', ', $invalid).'.' : 'The index check failed: '.$indexes->errorMessage(), ExitCode::RolledBack, 'invalid_index', null, true, ['indexes' => $invalid]);
        }

        return ['checked' => $checked];
    }

    private function opening(OperationRun $run, InstanceState $state, ComposeProject $project): CommandResult
    {
        $record = $run->record();
        $id = UpgradeJournal::shortId($record);
        $release = $record->phaseData('release');
        $backup = $record->phaseData('safety_backup');
        $open = $run->phase('open', fn (): array => $this->finish->open($state, $project, $backup, $id), always: true);
        $dropped = $record->phaseData('quiesce')['dropped_jobs'] ?? null;
        $result = CommandResult::ok('upgrade', [
            'from' => $release['from'] ?? null, 'to' => $release['version'] ?? $state->version(), 'url' => InstanceSettings::of($state)->instanceUrl(),
            'safety_backup' => $backup['backup'] ?? null, 'migrated' => UpgradeJournal::list($record->phaseData('migrate')['migrated'] ?? null),
            'removed_safety_backups' => UpgradeJournal::list($open['removed_safety_backups'] ?? null), 'dropped_jobs' => is_array($dropped) ? $dropped : null,
            'notes_url' => $release['notes_url'] ?? null,
        ], changed: true);
        if (is_array($dropped)) {
            $result = $result->withWarning('jobs_dropped', "{$dropped['count']} queued job(s) were dropped and saved to {$dropped['file']}.", 'Re-dispatch them from that file if they are still needed.');
        }
        foreach (is_array($open['warnings'] ?? null) ? $open['warnings'] : [] as $warning) {
            $result = is_array($warning) ? $result->withWarning((string) $warning[0], (string) $warning[1], isset($warning[2]) ? (string) $warning[2] : null) : $result;
        }

        return $result;
    }

    /** @param  list<string>  $services */
    private function logs(ComposeProject $project, array $services): string
    {
        try {
            return $this->docker->composeLogs($project, array_values(array_intersect($services, ['app', 'edge'])) ?: ['app'], null, 30);
        } catch (Throwable) {
            return '';
        }
    }
}
