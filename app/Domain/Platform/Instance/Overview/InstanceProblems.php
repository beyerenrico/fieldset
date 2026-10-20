<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Overview;

/**
 * The problems of the `/platform` instance page, in the order of plan U20 (R39, R47, R48): failed or stale backups, the
 * disk, an available update, and last the reminder that backups are held only on this host. Backup problems use the
 * alert codes `fsa status` reports as `warn` (BackupAlerts), with the same severity as the health page's backup check
 * and the command `fsa status` names in its hint; `backup_silent` is an fsa instance whose backup service never wrote a
 * row. Disk thresholds are the health page's (below 10 % free warns, below 5 % is a danger).
 */
final class InstanceProblems
{
    /** Backup alert => severity and command, worst first. */
    public const array BACKUP = [
        'backup_failed' => ['danger', 'fsa backup'],
        'backup_service_failed' => ['danger', 'fsa logs backup'],
        'backup_overdue' => ['danger', 'fsa doctor --post'],
        'backup_disk_space' => ['warning', 'fsa backup'],
        'backup_skipped' => ['warning', 'fsa status'],
        'backup_files_missing' => ['warning', null],
    ];

    public const string UPGRADE_COMMAND = 'fsa upgrade';

    /**
     * @param  list<array{code: string, run: array<string, mixed>|null}>  $alerts  the backup alerts (BackupRuns::summary)
     * @return list<InstanceProblem>
     */
    public static function backups(array $alerts, bool $silent): array
    {
        $problems = [];
        foreach (self::BACKUP as $code => [$severity, $command]) {
            $alert = array_find($alerts, static fn (array $alert): bool => $alert['code'] === $code);
            if ($alert !== null) {
                $problems[] = new InstanceProblem($code, $severity, self::backupText($code, $alert['run']), $command);
            }
        }
        if ($silent) {
            $problems[] = new InstanceProblem('backup_silent', 'warning', (string) __('instance.problems.backup_silent'), 'fsa logs backup');
        }

        return $problems;
    }

    /** @param  array{free: float, total: float}|null  $space */
    public static function disk(?array $space): ?InstanceProblem
    {
        if ($space === null) {
            return new InstanceProblem('disk_unknown', 'warning', (string) __('instance.problems.disk_unknown'));
        }
        $percent = (int) floor($space['free'] / $space['total'] * 100);
        if ($percent >= 10) {
            return null;
        }

        return new InstanceProblem('disk_low', $percent < 5 ? 'danger' : 'warning', (string) __('instance.problems.disk_low', ['percent' => $percent]), 'fsa status');
    }

    public static function update(?string $latest): ?InstanceProblem
    {
        return $latest === null ? null : new InstanceProblem('update_available', 'info', (string) __('instance.problems.update_available', ['version' => $latest]), self::UPGRADE_COMMAND);
    }

    public static function onHost(): InstanceProblem
    {
        return new InstanceProblem('backups_on_host', 'info', (string) __('instance.problems.backups_on_host'));
    }

    /** @param  array<string, mixed>|null  $run */
    private static function backupText(string $code, ?array $run): string
    {
        return (string) __('instance.problems.'.$code, [
            'reason' => is_string($run['reason'] ?? null) ? $run['reason'] : 'unknown',
            'count' => is_int($run['missing_files'] ?? null) ? $run['missing_files'] : 0,
        ]);
    }
}
