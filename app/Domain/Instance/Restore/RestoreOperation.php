<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Backup\SafetyBackup;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * `fsa restore <backup>` (R41, R64, R68, AE8, KTD18, KTD26, plan U17 steps 1–11, ADR 0085), journaled phase by phase:
 *
 * plan (the names, from the journal ID) → prepare (decrypt, check, judge: exit 2/7 before anything changes) → space
 * (exit 3) → quiesce (gate up, writers stopped, audit sealed) → ledger (final heads) → safety_backup → restore_database
 * (`<db>_restore_<id>`, the restricted role for unauthenticated archives) → restore_storage (a fresh volume) → finish
 * (new epoch + `instance.restored`, R64 carry, sequences, stale credentials, grants, decrypt sample) → swap (databases,
 * the state's storage volume, APP keys, Valkey) → verify (the app behind the gate, smoke token) → open (gate down,
 * everything starts) → cleanup (the put-aside database and volume, the scratch directory; the safety backup sealed).
 *
 * A failure before the swap exits 5 with the old instance running (RestoreRecovery::abort); from the swap on the
 * previous database and volume come back (exit 5), and when that fails too everything stops (exit 8). A resumed run
 * repeats the phases before the swap from a clean scratch state, returns to the previous instance when it finds a swap
 * that did not pass its verification, and finishes opening and cleaning up after one that did.
 */
final readonly class RestoreOperation
{
    public const string OPERATION = 'restore';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private InstanceLock $lock,
        private BackupIntake $intake,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreStack $stack,
        private RestoreRecovery $recovery,
        private RestoreCleanup $cleanup,
        private SafetyBackup $safety,
        private Ledger $ledger,
        private RestoreSpace $space,
    ) {}

    public function restore(RestoreRequest $request, FsaContext $context): CommandResult
    {
        $state = self::installed($this->directory);

        return $this->supervisor->run(
            self::OPERATION, $this->identity->holder('fsa', $request->actorUid),
            fn (OperationRun $run): CommandResult => $this->steps($run, $state, $request),
            resume: true, context: $request->toContext(), progress: $context->progress,
            accepts: static fn (JournalRecord $record): bool => ($record->context()['backup'] ?? null) === $request->backup,
        );
    }

    public static function installed(InstanceDirectory $directory): InstanceState
    {
        $state = $directory->hasState() ? $directory->state() : null;
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fsa install` first; `fsa install --from-backup <backup>` installs from a backup.');
        }

        return $state;
    }

    private function steps(OperationRun $run, InstanceState $state, RestoreRequest $request): CommandResult
    {
        $names = RestoreNames::fromArray($run->phase('plan', fn (): array => RestoreNames::for($state, $run->record()->id())->toArray(), changes: false));
        $project = ComposeProject::forInstance($state, $this->directory);
        $record = $run->record();
        if ($record->hasCompleted('swap')) {
            return $this->afterSwap($run, $state->withSetting('storage_volume', $names->freshVolume), $project, $names, $this->stack->raise($state), $record->phaseData('prepare'));
        }
        if ($record->hasStarted('swap')) { // killed during the swap: the state may already name the fresh volume
            $added = RestoreNames::strings($record->phaseData('prepare')['add'] ?? null);

            return $this->recovery->returnToPrevious($this->previousState($state, $names), $project, $names, new InstanceException('An earlier run of this restore stopped during the swap.', ExitCode::RolledBack, 'restore_interrupted'), $added, $this->safetyName($record));
        }
        try {
            $intake = $run->phase('prepare', fn (): array => $this->prepare($state, $request, $names), changes: false, always: true);
            $backup = PreparedBackup::fromArray($intake['backup']);
            $run->phase('space', fn (): array => $this->space->assert($backup), changes: false, always: true);
        } catch (Throwable $e) {
            $this->recovery->dropScratch($project, $names, databases: false);

            throw $e;
        }
        $token = '';
        try {
            $run->phase('quiesce', function () use ($state, $project, &$token): array {
                $token = $this->stack->quiesce($state, $project);

                return ['gate' => 'raised'];
            }, always: true);
            $previous = $run->phase('ledger', fn (): array => $this->recordLedger($state, $project, $backup, $request, $run->record()->id()));
            $run->phase('safety_backup', fn (): array => $this->safety->take($state, 'restore', new LockHandle($this->lockRecord())));
            $run->phase('restore_database', fn (): array => $this->restoreDatabase($state, $project, $backup, $names), always: true);
            $run->phase('restore_storage', function () use ($state, $backup, $names): array {
                $this->storage->remove($names->freshVolume); // what an interrupted attempt left (never the volume in use)
                $this->storage->fill($state, $names->freshVolume, $backup->storage['archive'] ?? null);

                return ['volume' => $names->freshVolume];
            }, always: true);
            $finish = $run->phase('finish', fn (): array => $this->databases->finish($project, $names->scratch, $names->live, $backup->name, $this->seed($previous), $request->actorUid, $previous['input'], $this->previousKeys($request, $intake)), always: true);
            $run->phase('erasures', fn (): array => $this->storage->removeErased($state, $names->freshVolume, $this->exportDirectories($finish)), always: true);
        } catch (Throwable $e) {
            return $this->recovery->abort($state, $project, $names, $e, $run->record()->hasStarted('quiesce'));
        }

        return $this->swapAndOpen($run, $state, $project, $names, $token, $intake, $finish, $request);
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, mixed>  $finish
     */
    private function swapAndOpen(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, string $token, array $intake, array $finish, RestoreRequest $request): CommandResult
    {
        $add = $this->keysToAdd($request, $intake);
        $swapped = $state->withSetting('storage_volume', $names->freshVolume);
        try {
            $run->phase('swap', function () use ($project, $names, $swapped, $add): array {
                $this->databases->swap($project, $names->scratch, $names->live, $names->aside);
                $secrets = $this->stack->secrets();
                $this->stack->apply($swapped, $secrets->withPreviousKeys([...$secrets->previousKeys, ...$add]));

                return ['flushed' => $this->stack->flushValkey($project), 'keys_added' => array_map(KeyFingerprint::of(...), $add)];
            });
        } catch (Throwable $e) {
            return $this->recovery->returnToPrevious($state, $project, $names, $e, array_map(KeyFingerprint::of(...), $add), $this->safetyName($run->record()));
        }

        return $this->afterSwap($run, $swapped, $project, $names, $token, $intake, $finish, $state);
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, mixed>|null  $finish
     */
    private function afterSwap(OperationRun $run, InstanceState $swapped, ComposeProject $project, RestoreNames $names, string $token, array $intake, ?array $finish = null, ?InstanceState $previous = null): CommandResult
    {
        $record = $run->record();
        $previous ??= $this->previousState($swapped, $names);
        try {
            if (! $record->hasStarted('cleanup')) { // once clean-up began, the previous database and volume may be gone
                $run->phase('verify', fn (): array => ['checked' => $this->stack->startAndVerify($swapped, $project, $token)], always: true);
                $run->phase('open', function () use ($swapped, $project): array {
                    $this->stack->reopen($swapped, $project);

                    return ['open' => true];
                }, always: true);
            }
        } catch (Throwable $e) {
            $added = RestoreNames::strings($run->record()->phaseData('swap')['keys_added'] ?? null);

            return $this->recovery->returnToPrevious($previous, $project, $names, $e, $added, $this->safetyName($run->record()), swapped: true);
        }
        $backupName = is_string($intake['backup']['name'] ?? null) ? $intake['backup']['name'] : null;
        $cleanup = $run->phase('cleanup', fn (): array => $this->cleanup->run($project, $names, $this->safetyName($run->record()), $run->record(), $backupName), always: true);

        return RestoreResult::ok($swapped, $intake, $finish ?? $run->record()->phaseData('finish'), $cleanup, $run->record());
    }

    /** @return array<string, mixed> */
    private function prepare(InstanceState $state, RestoreRequest $request, RestoreNames $names): array
    {
        $intake = $this->intake->take(self::runAs($state), $request, RestoreChecks::IN_PLACE, $names->id, AppKeys::of($this->stack->secrets()->appKeys()), $state->version());

        return ['backup' => $intake['backup']->toArray(), 'warnings' => $intake['warnings'], 'add' => array_map(KeyFingerprint::of(...), $intake['add'])];
    }

    /** @return array{image: string, uuid: string, user: string} */
    public static function runAs(InstanceState $state): array
    {
        return ['image' => $state->digests()['app'] ?? '', 'uuid' => $state->uuid(), 'user' => $state->containerUid().':'.($state->containerUid() === 0 ? 0 : $state->hostGid())];
    }

    /** @return array<string, mixed> the live database's final heads, appended to the ledger (R43, KTD26) */
    private function recordLedger(InstanceState $state, ComposeProject $project, PreparedBackup $backup, RestoreRequest $request, string $journal): array
    {
        $heads = $this->stack->heads($project);
        $seed = max($this->ledger->seed(), $heads['epoch']);
        $this->ledger->append(['action' => self::OPERATION, 'host_uid' => $request->actorUid, 'backup' => $backup->name, 'source' => $backup->source, 'journal' => $journal,
            'version' => $state->version(), 'epoch' => $heads['epoch'], 'heads' => (object) $heads['heads'], 'intact' => $heads['intact']]);

        return ['seed' => $seed, 'epoch' => $heads['epoch'], 'input' => ['previous_heads' => (object) $heads['heads'], 'previous_epoch' => $heads['epoch'], 'from_backup' => false]];
    }

    /** @return array<string, mixed> */
    private function restoreDatabase(InstanceState $state, ComposeProject $project, PreparedBackup $backup, RestoreNames $names): array
    {
        $this->databases->drop($project, $names->scratch, $names->role); // what an interrupted attempt left
        $created = $this->databases->create($project, $names->scratch, $backup->authenticated() ? null : $names->role);
        $as = $created['role'] === null ? null : ['username' => $created['role'], 'password' => (string) $created['password']];
        $this->databases->restore($state, $project, $backup, $names->scratch, $as);
        if ($created['role'] !== null) {
            $this->databases->audit($project, $names->scratch, $names->live); // the live database: migrated by this image
            $this->databases->adopt($project, $names->scratch, $created['role']);
        }

        return ['database' => $names->scratch, 'restricted' => $created['role'] !== null];
    }

    /**
     * The data-export directories of accounts erased since the backup (finish data `carried.export_directories`).
     *
     * @param  array<string, mixed>  $finish
     * @return list<array{disk: string, path: string}>
     */
    private function exportDirectories(array $finish): array
    {
        $listed = is_array($finish['carried'] ?? null) && is_array($finish['carried']['export_directories'] ?? null) ? $finish['carried']['export_directories'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $entry): ?array => is_array($entry) && is_string($entry['disk'] ?? null) && is_string($entry['path'] ?? null) ? ['disk' => $entry['disk'], 'path' => $entry['path']] : null,
            $listed,
        )));
    }

    /** @param  array<string, mixed>  $previous */
    private function seed(array $previous): int
    {
        return is_int($previous['seed'] ?? null) ? $previous['seed'] : 0;
    }

    /**
     * The key file's keys the backup lists and the instance lacks, as APP_PREVIOUS_KEYS for the restored database.
     *
     * @param  array<string, mixed>  $intake
     * @return list<string>
     */
    private function keysToAdd(RestoreRequest $request, array $intake): array
    {
        $wanted = is_array($intake['add'] ?? null) ? $intake['add'] : [];

        return array_values(array_filter($request->keyFile->keys ?? [], static fn (string $key): bool => in_array(KeyFingerprint::of($key), $wanted, true)));
    }

    /**
     * Every APP key the finish one-off decrypts with: the instance's previous keys and the key file's for this backup.
     *
     * @param  array<string, mixed>  $intake
     */
    private function previousKeys(RestoreRequest $request, array $intake): string
    {
        return implode(',', array_values(array_unique([...$this->stack->secrets()->previousKeys, ...$this->keysToAdd($request, $intake)])));
    }

    private function previousState(InstanceState $swapped, RestoreNames $names): InstanceState
    {
        $default = $swapped->project().'_app-storage';

        return $swapped->withSetting('storage_volume', $names->currentVolume === $default ? null : $names->currentVolume);
    }

    private function safetyName(JournalRecord $record): ?string
    {
        $name = $record->phaseData('safety_backup')['backup'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function lockRecord(): LockRecord
    {
        return $this->lock->current() ?? throw new InstanceException('The instance lock was lost during the restore.', ExitCode::AbortedUnchanged, 'lock_lost');
    }
}
