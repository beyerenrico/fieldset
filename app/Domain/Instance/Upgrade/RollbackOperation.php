<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\BackupIntake;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\Restore\RestoreChecks;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreRequest;
use App\Domain\Instance\Restore\RestoreSource;
use App\Domain\Instance\Restore\RestoreSpace;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * `fsa rollback` (R37, R43, R57, R64, R68, plan U18, ADR 0086): returns to the release before the last upgrade from
 * that upgrade's safety backup, journaled phase by phase like a restore (ADR 0085), whose primitives it uses:
 *
 * plan (RollbackGuard: the previous release, the safety backup, the loss; exit 7 otherwise) → prepare (the safety
 * backup verified, decrypted when sealed) → pull (the previous digests) → space (exit 3) → quiesce (like an upgrade:
 * the gate up, writers stopped, the queue drained, audit sealed) → ledger (the live database's final heads) → export
 * (the current release's security state, SecurityExport) → safety_backup (the pre-rollback one) → restore_database →
 * restore_storage → render (the **previous** image renders its own compose and env files for the current settings and
 * the fresh volume, render-only child) → finish (new epoch, `instance.rolled_back`, the R64 carry from the export,
 * sequences, stale credentials, in the previous image) → erasures → RollbackFinale (swap … cleanup).
 *
 * A failure before the swap goes back (RollbackRecovery::abort, exit 5); from the swap until the gate went down the
 * instance returns to the upgraded release (exit 5), exit 8 when that fails too. A resumed run decides like a restore's.
 */
final readonly class RollbackOperation
{
    public const string OPERATION = 'rollback';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private InstanceLock $lock,
        private DockerGateway $docker,
        private RollbackGuard $guard,
        private BackupIntake $intake,
        private RestoreStack $stack,
        private UpgradeQuiesce $quiesce,
        private UpgradePreflight $preflight,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private SafetyBackup $safety,
        private Ledger $ledger,
        private RestoreSpace $space,
        private UpgradeChild $child,
        private RollbackFinale $finale,
        private RollbackRecovery $recovery,
        private StagedRelease $staged,
        private ReleaseFiles $files,
    ) {}

    public function rollback(RestoreRequest $request, ProgressReporter $progress): CommandResult
    {
        $state = RestoreOperation::installed($this->directory);

        return $this->supervisor->run(self::OPERATION, $this->identity->holder('fsa', $request->actorUid),
            fn (OperationRun $run): CommandResult => $this->steps($run, $state, $request),
            resume: true, context: ['identity_file' => $request->identityFile], progress: $progress);
    }

    private function steps(OperationRun $run, InstanceState $state, RestoreRequest $request): CommandResult
    {
        $plan = $run->phase('plan', fn (): array => $this->plan($state, $run->record()->id()), changes: false);
        $names = RestoreNames::fromArray(is_array($plan['names'] ?? null) ? $plan['names'] : []);
        $project = ComposeProject::forInstance($state, $this->directory);
        $record = $run->record();
        if ($record->hasStarted('abort')) {
            return $this->recovery->abort($run, $state, $project, $names, RollbackRecovery::cause($run, 'abort'), true, $plan);
        }
        if ($record->hasStarted('return_stop') || ($record->hasStarted('swap') && ! $record->hasCompleted('swap'))) {
            return $this->recovery->returnTo($run, $plan, $project, $names, RollbackRecovery::cause($run, 'return_stop'));
        }
        if ($record->hasCompleted('swap')) {
            return $this->finale->open($run, $plan, $project, $names);
        }

        return $this->beforeSwap($run, $state, $project, $names, $plan, $request);
    }

    /** @return array<string, mixed> the guard's plan, the names, the state and compose file before, the loss */
    private function plan(InstanceState $state, string $journal): array
    {
        $plan = $this->guard->plan($state);
        $at = BackupName::parse($plan['backup'])?->at->toIso8601ZuluString();

        return [...$plan, 'names' => RestoreNames::for($state, $journal)->toArray(), 'state_before' => $state->toArray(),
            'compose_before' => (string) $this->directory->read(InstanceDirectory::COMPOSE_FILE), 'loss' => $at === null ? null : $this->guard->loss($state, $at)];
    }

    /** @param  array<string, mixed>  $plan */
    private function beforeSwap(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, array $plan, RestoreRequest $request): CommandResult
    {
        $quiesced = $run->record()->hasStarted('quiesce');
        try {
            if ($quiesced) {
                $this->recovery->dropScratch($project, $names);
            }
            $prepared = PreparedBackup::fromArray($run->phase('prepare', fn (): array => $this->prepare($state, $plan, $request, $names)->toArray(), changes: false, always: true));
            $run->phase('pull', fn (): array => $this->preflight->pull(['digests' => $plan['digests'] ?? []]), changes: false);
            $run->phase('space', fn (): array => $this->space->assert($prepared, $run->record()->hasCompleted('safety_backup')), changes: false, always: true);
        } catch (Throwable $e) {
            if ($quiesced) {
                return $this->recovery->abort($run, $state, $project, $names, $e, true, $plan);
            }
            $this->recovery->dropScratch($project, $names, databases: false);

            throw $e;
        }
        try {
            $this->restoreSide($run, $state, $project, $names, $plan, $prepared, $request);
        } catch (Throwable $e) {
            return $this->recovery->abort($run, $state, $project, $names, $e, $run->record()->hasStarted('quiesce'), $plan);
        }

        return $this->finale->swap($run, $plan, $project, $names);
    }

    /** @param  array<string, mixed>  $plan */
    private function restoreSide(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, array $plan, PreparedBackup $prepared, RestoreRequest $request): void
    {
        $run->phase('quiesce', fn (): array => $this->quiesce->quiesce($state, $project, false, $names->id, self::OPERATION), always: true);
        $ledger = $run->phase('ledger', fn (): array => $this->recordLedger($state, $project, $plan, $request->actorUid, $run->record()->id()));
        $export = $run->phase('export', fn (): array => $this->export($project, $prepared, $names), always: true);
        $run->phase('safety_backup', fn (): array => $this->safety->take($state, self::OPERATION, new LockHandle($this->lock->current() ?? throw new InstanceException('The instance lock was lost.', ExitCode::AbortedUnchanged, 'lock_lost'))));
        $run->phase('restore_database', function () use ($state, $project, $names, $prepared): array {
            $this->databases->drop($project, $names->scratch);
            $this->databases->create($project, $names->scratch, null);
            $this->databases->restore($state, $project, $prepared, $names->scratch, null);

            return ['database' => $names->scratch];
        }, always: true);
        $run->phase('restore_storage', function () use ($state, $names, $prepared): array {
            $this->storage->remove($names->freshVolume);
            $this->storage->fill($state, $names->freshVolume, $prepared->storage['archive'] ?? null);

            return ['volume' => $names->freshVolume];
        }, always: true);
        $run->phase('render', fn (): array => $this->render($state, $project, $plan, $names), always: true);
        $finish = $run->phase('finish', fn (): array => $this->databases->finish(RollbackFinale::stagedProject($state, $this->child->stage($names->id)), $names->scratch, null, $prepared,
            (int) ($ledger['seed'] ?? 0), $request->actorUid, [...(is_array($ledger['input'] ?? null) ? $ledger['input'] : []), 'carry' => $this->carry((string) $export['file'])],
            $run->record()->id(), '', 'rolled_back', true), always: true);
        $run->phase('erasures', fn (): array => $this->storage->removeErased($state, $names->freshVolume, RollbackFinale::exportDirectories($finish)), always: true);
    }

    /**
     * The previous image renders its own files (render-only child) for the current settings and the fresh volume, with
     * the env files in the layout it rendered them in before the upgrade (`operations/previous-release`) carrying the
     * current values, mounted over `env/` (the target may have renamed keys, review A3); then its stage passes the same
     * validation as an upgrade's before the finish step or the swap uses any of it (review S2).
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function render(InstanceState $state, ComposeProject $project, array $plan, RestoreNames $names): array
    {
        $env = $this->previousEnv($names->id);
        try {
            $result = $this->child->run($state, $project, [
                'version' => $plan['version'], 'digests' => $plan['digests'], 'from' => $plan['from'], 'settings' => ['storage_volume' => $names->freshVolume], 'migrate' => false, 'env' => $env,
            ], $names->id);
        } finally {
            if ($env !== null) {
                UpgradeFiles::remove(dirname($env));
            }
        }
        $this->staged->validate($state, $this->child->stage($names->id), UpgradeJournal::strings($plan['digests'] ?? null));

        return $result;
    }

    /** The merged env directory for the render child, or null without the previous release's files. */
    private function previousEnv(string $id): ?string
    {
        $previous = $this->files->envOf(ReleaseFiles::PREVIOUS);
        if ($previous === []) {
            return null;
        }
        $current = new EnvFiles($this->directory);
        $path = $this->directory->path(InstanceDirectory::OPERATIONS_DIR.'/rollback-env-'.$id);
        UpgradeFiles::remove($path);
        $merged = new EnvFiles(InstanceDirectory::initialize($path));
        foreach ($previous as $service => $values) {
            $now = $current->read($service);
            $merged->write($service, array_combine(array_keys($values), array_map(static fn (string $key, string $value): string => $now[$key] ?? $value, array_keys($values), $values)));
        }

        return $path.'/'.InstanceDirectory::ENV_DIR;
    }

    /**
     * The safety backup verified (decrypted with the identity when it was sealed). It is older than this image, which a
     * rollback expects (the drill's verdict allows that); it must be this instance's.
     *
     * @param  array<string, mixed>  $plan
     */
    private function prepare(InstanceState $state, array $plan, RestoreRequest $request, RestoreNames $names): PreparedBackup
    {
        $intake = $this->intake->take(RestoreOperation::runAs($state), new RestoreRequest((string) $plan['backup'], false, $request->identityFile, $request->passphrase, null, $request->actorUid),
            RestoreChecks::DRILL, $names->id, AppKeys::of($this->stack->secrets()->appKeys()), $state->version());
        $backup = $intake['backup'];
        if (($backup->manifest['instance']['uuid'] ?? null) !== $state->uuid()) {
            throw new InvalidInput("The safety backup {$backup->name} was not made by this instance.", 'backup_other_instance', 'Nothing was changed.');
        }

        return $backup;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{seed: int, input: array<string, mixed>}
     */
    private function recordLedger(InstanceState $state, ComposeProject $project, array $plan, int $actorUid, string $journal): array
    {
        $heads = $this->stack->heads($project);
        $seed = max($this->ledger->seed(), $heads['epoch']);
        $this->ledger->append(['action' => self::OPERATION, 'reason' => 'manual', 'host_uid' => $actorUid, 'backup' => $plan['backup'], 'journal' => $journal,
            'from' => $state->version(), 'to' => $plan['version'], 'epoch' => $heads['epoch'], 'heads' => (object) $heads['heads'], 'intact' => $heads['intact']]);

        return ['seed' => $seed, 'input' => ['previous_heads' => (object) $heads['heads'], 'previous_epoch' => $heads['epoch'], 'from_backup' => false]];
    }

    /**
     * The current release's security state (SecurityExport), in a 0600 file of the scratch directory.
     *
     * @return array{file: string, tables: int}
     */
    private function export(ComposeProject $project, PreparedBackup $prepared, RestoreNames $names): array
    {
        $since = RestoreDatabases::createdAt($prepared->manifest['created_at'] ?? null);
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:security-export', ...($since === null ? [] : ['--since='.$since]), '--json'], timeout: 1800.0));
        if (! $outcome->ok) {
            throw new InstanceException('Exporting the security state failed: '.$outcome->errorMessage(), ExitCode::RolledBack, 'export_failed', null, true, ['output' => $outcome->output]);
        }
        $file = InstanceDirectory::BACKUPS_DIR.'/'.RestoreSource::SCRATCH_PREFIX.$names->id.'/security-state.json';
        $this->directory->write($file, json_encode($outcome->data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), 0600);

        return ['file' => $file, 'tables' => count(is_array($outcome->data['tables'] ?? null) ? $outcome->data['tables'] : [])];
    }

    /** @return array<string, mixed> */
    private function carry(string $file): array
    {
        $document = json_decode((string) $this->directory->read($file), true);

        return is_array($document) ? $document : throw new InstanceException('The security state export is missing.', ExitCode::RolledBack, 'export_missing');
    }
}
