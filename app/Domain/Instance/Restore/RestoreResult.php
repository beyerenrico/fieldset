<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The envelope of a completed restore (plan U17, ADR 0085): which backup, the new audit epoch, what the R64 carry
 * re-applied, the sequences advanced, the stale credentials cleared, the safety backup, the storage volume and the
 * warnings (unauthenticated archive accepted, stray files ignored, clean-up left overs). Never a secret.
 */
final readonly class RestoreResult
{
    /**
     * @param  array<string, mixed>  $intake  phase `prepare`: backup, warnings, add
     * @param  array<string, mixed>  $finish  phase `finish`: the data of `instance:restore-finish`
     * @param  array{left_behind: list<string>, sealed: bool, warnings: list<array{0: string, 1: string, 2: string|null}>}|array<string, mixed>  $cleanup
     */
    public static function ok(InstanceState $state, array $intake, array $finish, array $cleanup, JournalRecord $record): CommandResult
    {
        $backup = is_array($intake['backup'] ?? null) ? PreparedBackup::fromArray($intake['backup']) : null;
        $result = CommandResult::ok(RestoreOperation::OPERATION, [
            'backup' => $backup?->name, 'format' => $backup?->format, 'authenticated' => $backup?->authenticated(), 'encrypted' => $backup?->encrypted,
            'created_at' => $backup?->manifest['created_at'] ?? null, 'backup_version' => $backup?->version(),
            'url' => InstanceSettings::of($state)->instanceUrl(), 'storage_volume' => RestoreStorage::current($state),
            'epoch' => $finish['epoch'] ?? null, 'previous_epoch' => $finish['previous_epoch'] ?? null,
            'carried' => $finish['carried'] ?? null, 'carry_mode' => $finish['carry_mode'] ?? null, 'carry_skipped' => $finish['carry_skipped'] ?? null,
            'cascade_skipped' => $finish['cascade_skipped'] ?? null, // kept with a form, group, provider … the live database lost (restored with it)
            'sequences' => $finish['sequences'] ?? null, 'cleared' => $finish['cleared'] ?? null,
            'decrypt' => $finish['decrypt'] ?? null,
            'safety_backup' => $record->phaseData('safety_backup')['backup'] ?? null, 'safety_backup_sealed' => ($cleanup['sealed'] ?? false) === true,
            'keys_added' => $record->phaseData('swap')['keys_added'] ?? [], 'valkey_flushed' => $record->phaseData('swap')['flushed'] ?? null,
            'left_behind' => $cleanup['left_behind'] ?? [],
        ], changed: true);
        $warnings = [...(is_array($intake['warnings'] ?? null) ? $intake['warnings'] : []), ...(is_array($cleanup['warnings'] ?? null) ? $cleanup['warnings'] : [])];
        foreach ($warnings as $warning) {
            if (is_array($warning) && is_string($warning[0] ?? null)) {
                $result = $result->withWarning($warning[0], (string) ($warning[1] ?? ''), is_string($warning[2] ?? null) ? $warning[2] : null);
            }
        }
        $skipped = self::skipped($finish['carry_skipped'] ?? null);
        if ($skipped !== []) {
            $result = $result->withWarning('carry_partial', 'The security-state carry left changes it could not attribute to an administrator after the backup: '.implode(', ', array_map(static fn (string $item, int $count): string => "{$item}: {$count}", array_keys($skipped), $skipped)).'.',
                'Check accounts, SSO, access and webhooks in the admin panel; ADR 0085 describes when the carry compares states and when it follows the audit log.');
        }
        $result = $result->withData(['webhooks' => 'paused'])->withWarning('webhooks_paused', 'Workflow webhooks are paused: restored runs could repeat deliveries made after the backup.', 'Check the webhook receivers, then run `fsa config set webhooks on`.');
        if (($record->phaseData('swap')['flushed'] ?? true) === false) {
            return $result->withWarning('valkey_not_flushed', 'Valkey could not be flushed: cached values and queued jobs of the replaced data may remain.', 'Run `fsa logs redis`; queued jobs that miss their data fail on their own.');
        }

        return $result;
    }

    /**
     * The non-zero counts of `carry_skipped`.
     *
     * @return array<string, int>
     */
    private static function skipped(mixed $counts): array
    {
        return array_filter(is_array($counts) ? $counts : [], static fn (mixed $count, mixed $item): bool => is_string($item) && is_int($count) && $count > 0, ARRAY_FILTER_USE_BOTH);
    }
}
