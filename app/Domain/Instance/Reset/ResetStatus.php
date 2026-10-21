<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\State\InstanceState;

/**
 * What `fieldset status` says about resets (R45, plan U23, ADR 0088), without a database: the last reset from the state's
 * history (`reset`, written once the new database has its epoch, so data recorded before it, such as `demo_seeded`, is
 * stale), and an interrupted reset from the journal: how far it got, whether the database was recreated already, whether
 * it was going back, and the commands that finish or abandon it. The hint follows the phase it stopped in (review A9):
 * no gate before the quiesce, 503 from the quiesce until the gate went down, a running instance after that.
 */
final class ResetStatus
{
    /** The state history event of a reset. */
    public const string EVENT = 'reset';

    /**
     * @return array{at: string|null, journal: string|null, epoch: int|null, safety_backup: string|null, audit_export: string|null, demo: bool}|null
     */
    public static function last(InstanceState $state): ?array
    {
        $resets = array_values(array_filter($state->history(), static fn (array $entry): bool => ($entry['event'] ?? null) === self::EVENT));
        if ($resets === []) {
            return null;
        }
        $entry = $resets[array_key_last($resets)];
        $string = static fn (string $key): ?string => is_string($entry[$key] ?? null) ? $entry[$key] : null;

        return [
            'at' => $string('at'), 'journal' => $string('journal'), 'epoch' => is_int($entry['epoch'] ?? null) ? $entry['epoch'] : null,
            'safety_backup' => $string('safety_backup'), 'audit_export' => $string('audit_export'), 'demo' => ($entry['demo'] ?? false) === true,
        ];
    }

    /** @return array{last: array<string, mixed>|null, interrupted: array<string, mixed>|null} */
    public static function of(InstanceState $state, ?JournalRecord $open): array
    {
        return ['last' => self::last($state), 'interrupted' => $open?->operation() === ResetOperation::OPERATION ? self::interrupted($open) : null];
    }

    /** What `fieldset status` and exit 9 `operation_unfinished` tell the operator to run. */
    public static function hint(JournalRecord $record): string
    {
        $command = ResetRequest::commandLine($record->context());
        $kept = self::kept($record);

        return match (true) {
            $record->hasStarted(ResetWayBack::PHASE) => "It stopped while going back; nothing was deleted. Run `fieldset reset --abandon` (or the same `{$command}`) to start the instance again.",
            ResetOperation::dropBegan($record) && $record->hasStarted('gate') => "The fresh instance runs already; run `{$command}` again to finish the clean-up.",
            ResetOperation::dropBegan($record) => "Run `{$command}` again: the database was recreated already, so it continues from there without asking (the old data ".($kept === null ? 'was not backed up' : "is in backups/{$kept}").'). Until then the instance answers 503.',
            ! $record->hasStarted('quiesce') => "It stopped before changing anything (no gate is up). Run `{$command} --yes` to reset, or `fieldset reset --abandon` to close it.",
            default => "Nothing was deleted yet, and the instance stays behind its gate (503): run `{$command} --yes` to finish the reset, or `fieldset reset --abandon` to start the instance again as it was.",
        };
    }

    /** @return array<string, mixed> */
    private static function interrupted(JournalRecord $record): array
    {
        $past = ResetOperation::dropBegan($record);

        return [
            'last_phase' => $record->lastStartedPhase(), 'database_recreated' => $past, 'going_back' => $record->hasStarted(ResetWayBack::PHASE),
            'safety_backup' => self::string($record->phaseData('safety_backup')['backup'] ?? null),
            'audit_export' => self::string($record->phaseData('audit_export')['file'] ?? null),
            'resume' => ResetRequest::commandLine($record->context()), 'abandon' => $past ? null : 'fieldset reset --abandon',
        ];
    }

    private static function kept(JournalRecord $record): ?string
    {
        return self::string($record->phaseData('safety_backup')['backup'] ?? null) ?? self::string($record->phaseData('audit_export')['file'] ?? null);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
