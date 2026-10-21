<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Restore\RestoreDatabases;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\Restore\RestoreSource;
use App\Domain\Instance\Restore\RestoreStack;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Throwable;

/**
 * The second half of `fieldset rollback` (plan U18, ADR 0086), from the swap on, like a restore's (ADR 0085): swap (databases
 * renamed, the previous image's staged files made live, the state names the previous release and the fresh volume and
 * keeps no previous release, Valkey flushed) → verify (the previous app alone behind the gate, smoke test) → open
 * (everything but the quick tunnel) → gate (down: from here on fail forward) → cleanup (the upgraded database and the
 * superseded volume go, the safety backups are sealed, the history records `rolled_back`).
 *
 * Webhooks stay paused after a rollback until `fieldset config set webhooks on` (M12 gate C2, as after a restore, ADR 0085):
 * workflow runs that were in flight at the safety backup come back and could repeat deliveries the receivers got since.
 * A signal is handled at the boundaries before verify, before open and before the gate: the upgraded release returns (O8).
 */
final readonly class RollbackFinale
{
    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private RestoreDatabases $databases,
        private RestoreStorage $storage,
        private RestoreStack $stack,
        private RestoreSource $source,
        private StagedRelease $staged,
        private UpgradeChild $child,
        private RollbackRecovery $recovery,
        private ReleaseFiles $files,
        private SafetySeal $seals,
        private Interruption $interruption,
    ) {}

    /** The compose project of the previous image's staged files (its one-offs run the previous image). */
    public static function stagedProject(InstanceState $state, string $stage): ComposeProject
    {
        $files = $stage.'/'.StageRenderer::FILES;

        return new ComposeProject($state->project(), $files.'/'.InstanceDirectory::COMPOSE_FILE, $files);
    }

    /**
     * @param  array<string, mixed>  $finish  the finish step's data
     * @return list<array{disk: string, path: string}>
     */
    public static function exportDirectories(array $finish): array
    {
        $listed = is_array($finish['carried'] ?? null) && is_array($finish['carried']['export_directories'] ?? null) ? $finish['carried']['export_directories'] : [];

        return array_values(array_filter(array_map(static fn (mixed $entry): ?array => is_array($entry) && is_string($entry['disk'] ?? null) && is_string($entry['path'] ?? null) ? ['disk' => $entry['disk'], 'path' => $entry['path']] : null, $listed)));
    }

    /**
     * The state after a rollback: the previous release, the fresh volume, no release to roll back to, webhooks paused (C2).
     *
     * @param  array<string, mixed>  $plan
     */
    public static function rolledBackState(InstanceState $before, array $plan, RestoreNames $names): InstanceState
    {
        return $before->withRelease((string) $plan['version'], UpgradeJournal::strings($plan['digests'] ?? null))->withoutPreviousRelease()
            ->withSetting('storage_volume', $names->freshVolume)->withSetting('webhooks_paused', true);
    }

    /** @param  array<string, mixed>  $plan */
    public function swap(OperationRun $run, array $plan, ComposeProject $project, RestoreNames $names): CommandResult
    {
        $before = InstanceState::fromArray(is_array($plan['state_before'] ?? null) ? $plan['state_before'] : []);
        $after = self::rolledBackState($before, $plan, $names);
        try {
            $run->phase('swap', function () use ($project, $names, $after): array {
                $this->databases->swap($project, $names->scratch, $names->live, $names->aside);
                $this->files->snapshot(RollbackRecovery::filesBefore($names->id)); // what a return puts back (review A10)
                $this->staged->install($this->child->stage($names->id));
                $this->directory->saveState($after);
                new RuntimeWriter($this->directory)->write($after);

                return ['flushed' => $this->stack->flushValkey($project)];
            });
        } catch (Throwable $e) {
            return $this->recovery->returnTo($run, $plan, $project, $names, $e);
        }

        return $this->open($run, $plan, $project, $names);
    }

    /** @param  array<string, mixed>  $plan */
    public function open(OperationRun $run, array $plan, ComposeProject $project, RestoreNames $names): CommandResult
    {
        $after = self::rolledBackState(InstanceState::fromArray(is_array($plan['state_before'] ?? null) ? $plan['state_before'] : []), $plan, $names);
        $record = $run->record();
        if (! $record->hasStarted('gate')) {
            try {
                $this->interruption->checkpoint();
                if (! $record->hasCompleted('verify')) {
                    $token = $this->stack->raise($after);
                    $run->phase('verify', fn (): array => ['checked' => $this->stack->startAndVerify($after, $project, $token)], always: true);
                    $this->interruption->checkpoint();
                }
                $run->phase('open', function () use ($project): array {
                    $services = array_values(array_diff(UpgradeCompose::services((string) $this->directory->read(InstanceDirectory::COMPOSE_FILE)), ['tunnel']));
                    $this->docker->composeUp($project, $services, RestoreStack::WAIT_SECONDS, removeOrphans: true); // services only the upgrade had go

                    return ['started' => $services];
                }, always: true);
                $this->interruption->checkpoint();
            } catch (Throwable $e) {
                return $this->recovery->returnTo($run, $plan, $project, $names, $e);
            }
        }
        $run->phase('gate', function () use ($after): array {
            $this->stack->lowerGate($after);

            return ['lowered' => true];
        }, always: true);
        $cleanup = $run->phase('cleanup', fn (): array => $this->cleanup($project, $names, $plan, $run), always: true);

        return $this->result($plan, $names, $run, $cleanup);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{left_behind: list<string>, warnings: list<array{0: string, 1: string, 2: string|null}>}
     */
    private function cleanup(ComposeProject $project, RestoreNames $names, array $plan, OperationRun $run): array
    {
        $left = [];
        $warnings = [];
        try {
            $this->databases->drop($project, $names->aside);
        } catch (Throwable) {
            $left[] = "database {$names->aside}";
        }
        if ($names->currentVolume !== $names->freshVolume && ! $this->storage->remove($names->currentVolume)) {
            $left[] = "volume {$names->currentVolume}";
        }
        UpgradeFiles::remove($this->child->stage($names->id));
        try {
            $this->source->discard($names->id);
        } catch (Throwable) {
            $left[] = 'directory '.RestoreSource::scratchPath($names->id);
        }
        $safety = $run->record()->phaseData('safety_backup')['backup'] ?? null;
        $warnings = [...$warnings, ...$this->seals->seal([is_string($safety) ? $safety : null, is_string($plan['backup'] ?? null) ? $plan['backup'] : null,
            ...$this->seals->since(RollbackOperation::OPERATION, $run->record()->startedAt())])]; // also a set a killed attempt wrote unnamed (C3)
        $this->files->discard(RollbackRecovery::filesBefore($names->id));
        UpgradeFiles::remove($this->directory->path(RollbackRecovery::envDirectory($names->id))); // a killed render's (review V4)
        $this->files->discard(ReleaseFiles::PREVIOUS); // no release to roll back to any more
        $this->record($plan, $run, is_string($safety) ? $safety : null);
        if ($left !== []) {
            $warnings[] = ['rollback_cleanup_incomplete', 'The rollback succeeded, but these could not be removed: '.implode(', ', $left).'.', 'Remove them by hand (`docker volume rm`, `DROP DATABASE`).'];
        }

        return ['left_behind' => $left, 'warnings' => $warnings];
    }

    /** @param  array<string, mixed>  $plan */
    private function record(array $plan, OperationRun $run, ?string $safety): void
    {
        $state = $this->directory->state();
        $history = $state->history();
        $last = $history === [] ? [] : $history[array_key_last($history)];
        if (($last['event'] ?? null) === 'rolled_back' && ($last['journal'] ?? null) === $run->record()->id()) {
            return;
        }
        $epoch = $run->record()->phaseData('finish')['epoch'] ?? null;
        $this->directory->saveState($state->withHistoryEntry('rolled_back', [
            'from' => is_string($plan['from'] ?? null) ? $plan['from'] : null, 'to' => (string) $plan['version'], 'backup' => (string) $plan['backup'],
            'safety_backup' => $safety, 'journal' => $run->record()->id(), 'epoch' => is_int($epoch) ? $epoch : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $cleanup
     */
    private function result(array $plan, RestoreNames $names, OperationRun $run, array $cleanup): CommandResult
    {
        $finish = $run->record()->phaseData('finish');
        $result = CommandResult::ok('rollback', [
            'from' => $plan['from'] ?? null, 'to' => $plan['version'], 'backup' => $plan['backup'], 'safety_backup' => $run->record()->phaseData('safety_backup')['backup'] ?? null,
            'loss' => $plan['loss'] ?? null, 'epoch' => $finish['epoch'] ?? null, 'carried' => $finish['carried'] ?? null, 'carry_mode' => $finish['carry_mode'] ?? null,
            'storage_volume' => $names->freshVolume, 'left_behind' => $cleanup['left_behind'] ?? [], 'webhooks' => 'paused',
        ], changed: true);
        $result = $result->withWarning('webhooks_paused', 'Workflow webhooks are paused: runs restored from the safety backup could repeat deliveries made since.', 'Check the webhook receivers, then run `fieldset config set webhooks on`.');
        foreach (is_array($cleanup['warnings'] ?? null) ? $cleanup['warnings'] : [] as $warning) {
            $result = is_array($warning) ? $result->withWarning((string) $warning[0], (string) $warning[1], isset($warning[2]) ? (string) $warning[2] : null) : $result;
        }

        return $result;
    }
}
