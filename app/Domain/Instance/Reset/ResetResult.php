<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Lifecycle\MailSummary;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The envelope of a finished `fieldset reset` (R43, R49, plan U23, ADR 0088): the URL and mode, the fresh organization and
 * owner, what kept the old data (`safety_backup`, or `audit_export` with `--no-backup`), the epochs, the new storage
 * volume, whether Valkey and the catcher were emptied, and the next commands (with the instance's own `fieldset`). The owner's sign-in link and the demo
 * passwords are added by the caller as one-time secrets.
 */
final class ResetResult
{
    /**
     * @param  array<string, mixed>  $epoch  the epoch phase's data
     * @param  array<string, mixed>  $flushed  the flush phase's data
     * @param  array<string, mixed>  $cleanup  the cleanup phase's data
     * @param  list<array{0: string, 1: string, 2: string|null}>  $warnings
     */
    public static function ok(InstanceDirectory $directory, InstanceState $state, ResetSubject $subject, JournalRecord $record, array $epoch, array $flushed, array $cleanup, array $warnings): CommandResult
    {
        $settings = InstanceSettings::of($state);
        $fieldset = $directory->root.'/fieldset';
        $export = $record->phaseData('audit_export');
        $left = is_array($cleanup['left_behind'] ?? null) ? array_values($cleanup['left_behind']) : [];
        $result = CommandResult::ok(ResetOperation::OPERATION, [
            'url' => $settings->instanceUrl(), 'mode' => $state->mode(), 'version' => $state->version(), 'mail' => MailSummary::of($state),
            'organization' => $subject->organization, 'owner_email' => $subject->ownerEmail,
            'safety_backup' => self::safetyBackup($record), 'audit_export' => is_string($export['file'] ?? null) ? $export['file'] : null,
            'epoch' => $epoch['epoch'] ?? null, 'previous_epoch' => $epoch['previous_epoch'] ?? null,
            'storage_volume' => RestoreStorage::current($state), 'flushed' => ['valkey' => ($flushed['valkey'] ?? false) === true, 'catcher' => $flushed['catcher'] ?? null],
            'left_behind' => $left, 'removed' => is_array($cleanup['removed'] ?? null) ? array_values($cleanup['removed']) : [],
            'next_commands' => ["{$fieldset} status", "{$fieldset} login-link {$subject->ownerEmail}", "{$fieldset} doctor --post"],
        ], changed: true);
        foreach (self::warnings($record, $flushed, $left, $warnings) as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return $result;
    }

    public static function safetyBackup(JournalRecord $record): ?string
    {
        $name = $record->phaseData('safety_backup')['backup'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Every safety backup the journal took, also one a retake superseded (verification V2): all are sealed (R57).
     *
     * @return list<string>
     */
    public static function safetyBackups(JournalRecord $record): array
    {
        $names = array_map(static fn (array $phase): mixed => $phase['data']['backup'] ?? null, array_filter($record->phases(), static fn (array $phase): bool => $phase['name'] === 'safety_backup' && $phase['status'] === 'completed'));

        return array_values(array_unique(array_filter($names, static fn (mixed $name): bool => is_string($name) && $name !== '')));
    }

    /**
     * @param  array<string, mixed>  $flushed
     * @param  list<mixed>  $left
     * @param  list<array{0: string, 1: string, 2: string|null}>  $warnings
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    private static function warnings(JournalRecord $record, array $flushed, array $left, array $warnings): array
    {
        $noBackup = ($record->context()['no_backup'] ?? false) === true;
        $export = $record->phaseData('audit_export')['file'] ?? null;

        return [
            ...($noBackup ? [['no_backup', is_string($export)
                ? "No safety backup was taken (--no-backup): only the audit log was kept, in backups/{$export}."
                : 'No safety backup was taken (--no-backup) and only demo organizations existed: nothing of the old data was kept.', null]] : []),
            ...(($flushed['catcher'] ?? null) === false ? [['catcher_not_flushed', 'The mail catcher could not be emptied; it still shows mail of the old data.', 'Delete the messages in the catcher UI.']] : []),
            ...($left !== [] ? [['reset_cleanup_incomplete', 'The reset succeeded, but these could not be removed: '.implode(', ', array_map(strval(...), array_filter($left, is_string(...)))).'.', 'Remove them by hand (`docker volume rm`, `DROP DATABASE`).']] : []),
            ...$warnings,
        ];
    }
}
