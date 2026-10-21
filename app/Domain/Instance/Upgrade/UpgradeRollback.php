<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Compose\Services\ValkeyService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Restore\RestoreHalt;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The automatic rollback of an upgrade (R35, KTD19, plan U18, ADR 0086), from the safety backup until the commit,
 * through U17's atomic restore path (ADR 0085), journaled phase by phase:
 *
 * rollback_stop (the child removed and its session terminated, queue, scheduler and backup stopped, the release files
 * step 7 replaced put back and the previous release rendered again from the state, which still names it; its app takes
 * the target's place behind the gate and orphans go) → rollback_ledger (the safety backup's heads:
 * writers were stopped since it was taken) → rollback_prepare (the safety backup verified into the scratch directory)
 * → rollback_database (`<db>_restore_<id>` from its dump) → rollback_storage (a fresh volume) → rollback_finish (a new
 * audit epoch with `instance.rolled_back` first, without the R64 carry and the sequence floors) → rollback_swap
 * (databases renamed, the state names the fresh volume, Valkey flushed) → rollback_verify (the previous app behind the
 * gate passes the smoke test) → rollback_open (everything but the quick tunnel starts, the gate goes down) →
 * rollback_cleanup (UpgradeRollbackCleanup). Exit 5 with the cause and its logs; any failure until the gate went down
 * is exit 8 (UpgradeHalt), and `fieldset upgrade --resume` repeats the rollback: phases before the swap from a clean
 * scratch state, the swap only when the databases say it did not happen; once `rollback_open` completed, only the
 * cleanup (review A2).
 */
final readonly class UpgradeRollback
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreStack $stack,
        private UpgradeBackup $backup,
        private UpgradeChild $child,
        private Ledger $ledger,
        private UpgradeHalt $halt,
        private UpgradeRollbackCleanup $cleanup,
        private ReleaseFiles $files,
    ) {}

    public function rollback(OperationRun $run, InstanceState $state, ComposeProject $project, Throwable $cause, int $actorUid): CommandResult
    {
        $record = $run->record();
        $release = $record->phaseData('release');
        $safety = $record->phaseData('safety_backup');
        $backupName = is_string($safety['backup'] ?? null) ? $safety['backup'] : null;
        $names = UpgradeJournal::names($record, $state);
        $swapped = $state->withSetting('storage_volume', $names->freshVolume);
        if (! $run->record()->hasCompleted('rollback_open')) { // once reopened, a resumed run only cleans up (review A2)
            try {
                $this->toOpen($run, $state, $project, $names, $cause, $backupName, $release, $swapped, $actorUid);
            } catch (Throwable $e) {
                return $this->halt->stop($project, $names, $release, $backupName, $cause, $e);
            }
        }
        $cleanup = $run->phase('rollback_cleanup', fn (): array => $this->cleanup->run($project, $names, $backupName, $run->record(), $release), always: true);

        return $this->result($cause, $release, $backupName, $names, $cleanup);
    }

    /** @param  array<string, mixed>  $release */
    private function toOpen(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, Throwable $cause, ?string $backupName, array $release, InstanceState $swapped, int $actorUid): void
    {
        $resumed = $run->record()->hasStarted('rollback_swap');
        if (! $resumed) { // once the swap began, the restored database may be live: never restore over it again
            $this->beforeSwap($run, $state, $project, $names, $cause, $backupName, $release, UpgradeJournal::shortId($run->record()), $actorUid);
        }
        if (! $run->record()->hasCompleted('rollback_swap')) {
            $run->phase('rollback_swap', fn (): array => $this->swap($project, $names, $swapped, $resumed));
        }
        if (! $run->record()->hasStarted('rollback_open')) { // verified already; the gate may be down: only open again (review V2)
            $run->phase('rollback_verify', fn (): array => ['checked' => $this->stack->startAndVerify($swapped, $project, $this->stack->raise($swapped))], always: true);
        }
        $run->phase('rollback_open', fn (): array => ['started' => $this->open($swapped, $project)], always: true);
    }

    /** The digest of the safety backup's SHA256SUMS the upgrade verified (gate S4); null in a journal from before it. */
    private function sums(OperationRun $run): ?string
    {
        $sums = $run->record()->phaseData('safety_backup')['sums'] ?? null;

        return is_string($sums) ? $sums : null;
    }

    /** The cause an interrupted rollback was rolling back from (its first phase's start data). */
    public static function cause(OperationRun $run): InstanceException
    {
        $data = $run->record()->lastAttemptData('rollback_stop');

        return new InstanceException(is_string($data['message'] ?? null) ? $data['message'] : 'An earlier run of this upgrade stopped before it committed.', ExitCode::RolledBack,
            is_string($data['code'] ?? null) ? $data['code'] : 'upgrade_interrupted', null, true, is_string($data['logs'] ?? null) ? ['logs' => $data['logs']] : []);
    }

    /** @param  array<string, mixed>  $release */
    private function beforeSwap(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, Throwable $cause, ?string $backupName, array $release, string $id, int $actorUid): void
    {
        if ($backupName === null) {
            throw new InstanceException('The journal names no safety backup to roll back to.', ExitCode::RollbackFailed, 'safety_backup_missing');
        }
        $logs = $cause instanceof InstanceException && is_string($cause->data['logs'] ?? null) ? $cause->data['logs'] : null;
        $run->phase('rollback_stop', fn (): array => ['names' => $names->toArray(), ...$this->stop($state, $project, $id)], [
            'code' => $cause instanceof InstanceException ? $cause->errorCode : 'upgrade_failed', 'message' => $cause->getMessage(), 'logs' => $logs,
        ]);
        $heads = $run->phase('rollback_ledger', fn (): array => $this->recordLedger($backupName, $release, $run->record()->id(), $actorUid));
        $prepared = PreparedBackup::fromArray($run->phase('rollback_prepare', fn (): array => $this->backup->prepare($state, $backupName, $id, $actorUid, $this->sums($run))->toArray(), always: true));
        $run->phase('rollback_database', function () use ($state, $project, $names, $prepared): array {
            $this->databases->drop($project, $names->scratch); // what an interrupted attempt left
            $this->databases->create($project, $names->scratch, null);
            $this->databases->restore($state, $project, $prepared, $names->scratch, null);

            return ['database' => $names->scratch];
        }, always: true);
        $run->phase('rollback_storage', function () use ($state, $names, $prepared): array {
            $this->storage->remove($names->freshVolume); // never the volume in use: the fresh name is this rollback's own
            $this->storage->fill($state, $names->freshVolume, $prepared->storage['archive'] ?? null);

            return ['volume' => $names->freshVolume];
        }, always: true);
        $run->phase('rollback_finish', fn (): array => $this->databases->finish($project, $names->scratch, null, $prepared, (int) ($heads['seed'] ?? 0), $actorUid,
            is_array($heads['input'] ?? null) ? $heads['input'] : [], $run->record()->id(), '', 'rolled_back', false), always: true);
    }

    /**
     * The child gone and the writers stopped; the release files step 7 replaced put back (the target may have changed
     * the env layout: its env files would lose or regenerate secrets, review A3) and the previous release rendered again
     * from the state, which still names it. Its app replaces the target's behind the raised gate, so external requests
     * keep getting 503 while the data is restored (R62, review A9); containers of services only the target had go
     * (orphans, review A4).
     *
     * @return array{child: string, app: bool}
     */
    private function stop(InstanceState $state, ComposeProject $project, string $id): array
    {
        try {
            $this->child->stop($state, $project, $id); // a child an interrupted run left behind
        } catch (Throwable) {
            // no child, or the database is down: nothing holds the session then
        }
        $this->docker->composeStop($project, array_values(array_diff(RestoreHalt::WRITERS, [AppRoles::APP])));
        $this->files->restore(UpgradeJournal::filesBefore($id));
        $this->stack->apply($state);
        $this->stack->raise($state);
        $this->docker->composeUp($project, [PostgresService::NAME, ValkeyService::NAME], RestoreStack::WAIT_SECONDS, removeOrphans: true);
        try {
            $this->docker->composeUp($project, [AppRoles::APP], RestoreStack::WAIT_SECONDS);
            $app = true;
        } catch (Throwable) {
            $this->docker->composeStop($project, [AppRoles::APP]); // the restore does not need it; the verification starts it
            $app = false;
        }

        return ['child' => 'stopped', 'app' => $app];
    }

    /**
     * The ledger line: the safety backup's own heads, which are the replaced database's (writers were stopped since).
     *
     * @param  array<string, mixed>  $release
     * @return array{seed: int, input: array<string, mixed>}
     */
    private function recordLedger(string $backupName, array $release, string $journal, int $actorUid): array
    {
        $heads = $this->backup->heads($backupName);
        $seed = max($this->ledger->seed(), $heads['epoch']);
        $this->ledger->append(['action' => 'rollback', 'reason' => 'automatic', 'host_uid' => $actorUid, 'backup' => $backupName, 'journal' => $journal,
            'from' => $release['version'] ?? null, 'to' => $release['from'] ?? null, 'epoch' => $heads['epoch'], 'heads' => (object) $heads['heads'], 'source' => 'safety_backup']);

        return ['seed' => $seed, 'input' => ['previous_heads' => (object) $heads['heads'], 'previous_epoch' => $heads['epoch'], 'from_backup' => false]];
    }

    /**
     * Renames the restored database in (once: a resumed swap looks at the databases first), renders the state that
     * names the fresh volume, and flushes Valkey.
     *
     * @return array{flushed: bool}
     */
    private function swap(ComposeProject $project, RestoreNames $names, InstanceState $swapped, bool $resumed): array
    {
        $databases = $resumed ? $this->databases->list($project, '') : [];
        $scratch = ! $resumed || in_array($names->scratch, $databases, true);
        if ($scratch && (! $resumed || in_array($names->live, $databases, true))) {
            $this->databases->swap($project, $names->scratch, $names->live, $names->aside);
        } elseif ($scratch) {
            $this->databases->rename($project, $names->scratch, $names->live); // killed between the two renames
        }
        $this->stack->apply($swapped);

        return ['flushed' => $this->stack->flushValkey($project)];
    }

    /** @return list<string> every service but the quick tunnel (a restart would change its host name), then the gate goes down */
    private function open(InstanceState $state, ComposeProject $project): array
    {
        $services = array_values(array_diff(UpgradeCompose::services((string) $this->directory->read(InstanceDirectory::COMPOSE_FILE)), ['tunnel']));
        $this->docker->composeUp($project, $services, RestoreStack::WAIT_SECONDS, removeOrphans: true);
        $this->stack->lowerGate($state);

        return $services;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $cleanup
     */
    private function result(Throwable $cause, array $release, ?string $backupName, RestoreNames $names, array $cleanup): CommandResult
    {
        $code = $cause instanceof InstanceException ? $cause->errorCode : 'upgrade_failed';
        $logs = $cause instanceof InstanceException && is_string($cause->data['logs'] ?? null) ? $cause->data['logs'] : '';
        $to = (string) ($release['version'] ?? '');
        $from = (string) ($release['from'] ?? '');
        $result = CommandResult::failed('upgrade', ExitCode::RolledBack, $code,
            "The upgrade to {$to} failed and was rolled back; the instance runs {$from} again with the data of the safety backup. Cause: ".$cause->getMessage(),
            'Read the logs in `data.logs` and `fieldset logs app`; nothing written since the safety backup was lost (writers were stopped). Run `fieldset upgrade` again once the cause is fixed.',
            true, ['from' => $from, 'to' => $to, 'running' => $from, 'safety_backup' => $backupName, 'logs' => $logs, 'storage_volume' => $names->freshVolume,
                'left_behind' => $cleanup['left_behind'] ?? []], changed: true);
        foreach (is_array($cleanup['warnings'] ?? null) ? $cleanup['warnings'] : [] as $warning) {
            $result = is_array($warning) ? $result->withWarning((string) $warning[0], (string) $warning[1], isset($warning[2]) ? (string) $warning[2] : null) : $result;
        }

        return $result;
    }
}
