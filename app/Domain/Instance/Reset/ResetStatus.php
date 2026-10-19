<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\State\InstanceState;

/**
 * What `fsa status` says about resets (R45, plan U23, ADR 0088), without a database: the last reset from the state's
 * history (`reset`, written once the new database has its epoch, so data recorded before it, such as `demo_seeded`, is
 * stale), and an interrupted reset from the journal: how far it got, whether the database was recreated already (then
 * the instance stays behind its gate until the reset finishes), and the command that finishes it.
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

    /** What `fsa status` and exit 9 `operation_unfinished` tell the operator to run. */
    public static function hint(JournalRecord $record): string
    {
        $command = ResetRequest::commandLine($record->context());
        if ($record->hasStarted(ResetOperation::DROP)) {
            $kept = self::kept($record);

            return "Run `{$command}` again: the database was recreated already, so it continues from there without asking (the old data ".($kept === null ? 'was not backed up' : "is in backups/{$kept}").'). Until then the instance answers 503.';
        }

        return "Run `{$command} --yes` again to finish it: nothing was deleted yet, and the instance stays behind its gate (503) until the reset finishes.";
    }

    /** @return array<string, mixed> */
    private static function interrupted(JournalRecord $record): array
    {
        return [
            'last_phase' => $record->lastStartedPhase(), 'database_recreated' => $record->hasStarted(ResetOperation::DROP),
            'safety_backup' => self::string($record->phaseData('safety_backup')['backup'] ?? null),
            'audit_export' => self::string($record->phaseData('audit_export')['file'] ?? null),
            'resume' => ResetRequest::commandLine($record->context()),
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
