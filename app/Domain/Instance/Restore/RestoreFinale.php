<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The second half of `fsa restore` (plan U17 steps 10–11, KTD18, ADR 0085), from the swap on:
 *
 * swap (databases renamed, the state names the fresh volume and pauses webhooks, APP keys rendered, Valkey flushed) →
 * verify (the app alone behind the gate, the smoke test with the gate's token) → open (every service starts, still
 * behind the gate) → gate (the gate goes down) → cleanup.
 *
 * Until the gate goes down a failure returns to the previous database and volume (RestoreRecovery); from then on the
 * restored instance takes requests, so nothing ever swaps back (review A4): the gate and clean-up phases fail forward.
 * A resumed run never raises the gate again once lowering began (review A1). Webhooks stay paused after an in-place
 * restore until `fsa config set webhooks on` (review A15): restored runs may repeat deliveries made after the backup.
 * A signal (Interruption, M12 gate O8) is handled at the boundaries before verify, before open and before the gate: the
 * instance returns to the previous database and volume, as on a failed verification. Never with `$wayBack` false.
 */
final readonly class RestoreFinale
{
    public function __construct(
        private RestoreDatabases $databases,
        private RestoreStack $stack,
        private RestoreRecovery $recovery,
        private RestoreCleanup $cleanup,
        private RestoreHalt $halt,
        private Interruption $interruption,
    ) {}

    /**
     * @param  array<string, mixed>  $intake
     * @param  list<string>  $add  APP keys to add as previous keys
     */
    public function swap(OperationRun $run, InstanceState $state, ComposeProject $project, RestoreNames $names, array $intake, array $add): CommandResult
    {
        $swapped = self::swappedState($state, $names);
        try {
            $run->phase('swap', function () use ($project, $names, $swapped, $add): array {
                $this->databases->swap($project, $names->scratch, $names->live, $names->aside);
                $secrets = $this->stack->secrets();
                $this->stack->apply($swapped, $secrets->withPreviousKeys([...$secrets->previousKeys, ...$add]));

                return ['flushed' => $this->stack->flushValkey($project), 'keys_added' => array_map(KeyFingerprint::of(...), $add)];
            });
        } catch (Throwable $e) {
            return $this->recovery->returnToPrevious($run, $state, $project, $names, $e, array_map(KeyFingerprint::of(...), $add), self::safety($run->record()));
        }

        return $this->open($run, $swapped, $state, $project, $names, $intake);
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  bool  $wayBack  false once the previous database or volume is gone (`--abandon` goes forward, L4): a failure
     *                         then stops everything (exit 8) instead of returning
     */
    public function open(OperationRun $run, InstanceState $swapped, InstanceState $previous, ComposeProject $project, RestoreNames $names, array $intake, bool $wayBack = true): CommandResult
    {
        $record = $run->record();
        if (! $record->hasStarted('gate') && ! $record->hasStarted('cleanup')) {
            try {
                if (! $wayBack) {
                    $this->stack->apply($swapped); // the restored database's state: fresh volume, paused webhooks
                }
                $this->checkpoint($wayBack);
                if (! $record->hasCompleted('verify')) {
                    $token = $this->stack->raise($swapped);
                    $run->phase('verify', fn (): array => ['checked' => $this->stack->startAndVerify($swapped, $project, $token)], always: true);
                    $this->checkpoint($wayBack);
                }
                $run->phase('open', function () use ($project): array {
                    $this->stack->startAll($project); // behind the gate: nothing is accepted until everything runs

                    return ['open' => true];
                }, always: true);
                $this->checkpoint($wayBack);
            } catch (Throwable $e) {
                if (! $wayBack) {
                    return $this->halt->stop($project, $names, $e, $e, self::safety($run->record()), 'forward');
                }
                $added = RestoreNames::strings($run->record()->phaseData('swap')['keys_added'] ?? null);

                return $this->recovery->returnToPrevious($run, $previous, $project, $names, $e, $added, self::safety($run->record()), swapped: true);
            }
        }
        $run->phase('gate', function () use ($swapped): array {
            $this->stack->lowerGate($swapped); // from here on: fail forward, never back

            return ['lowered' => true];
        }, always: true);
        $backup = is_string($intake['backup']['name'] ?? null) ? $intake['backup']['name'] : null;
        $cleanup = $run->phase('cleanup', fn (): array => $this->cleanup->run($project, $names, self::safety($run->record()), $run->record(), $backup), always: true);

        return RestoreResult::ok($swapped, $intake, $run->record()->phaseData('finish'), $cleanup, $run->record());
    }

    /** A signal received since the last boundary stops here, while there is a way back (O8). */
    private function checkpoint(bool $wayBack): void
    {
        if ($wayBack) {
            $this->interruption->checkpoint();
        }
    }

    /** The state after the swap: the fresh storage volume, webhooks paused until the operator confirms (review A15). */
    public static function swappedState(InstanceState $state, RestoreNames $names): InstanceState
    {
        return $state->withSetting('storage_volume', $names->freshVolume)->withSetting('webhooks_paused', true);
    }

    /** The state before the swap, whatever the current one says: the previous volume and webhook setting. */
    public static function previousState(InstanceState $current, RestoreNames $names, JournalRecord $record): InstanceState
    {
        $default = $current->project().'_app-storage';
        $paused = ($record->phaseData('plan')['webhooks_paused_before'] ?? false) === true;

        return $current->withSetting('storage_volume', $names->currentVolume === $default ? null : $names->currentVolume)
            ->withSetting('webhooks_paused', $paused ? true : null);
    }

    public static function safety(JournalRecord $record): ?string
    {
        $name = $record->phaseData('safety_backup')['backup'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
