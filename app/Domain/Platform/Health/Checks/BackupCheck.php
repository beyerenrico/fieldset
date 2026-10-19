<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use App\Domain\Platform\Instance\BackupRuns;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * Backups of an fsa instance (R39, ADR 0084), from the `backup_runs` table the backup service writes, as a persistent
 * warning on /platform, so a problem is visible while mail is captured:
 *
 * - fail: the last backup failed, the backup service's start failed, or no backup succeeded within two hours of the
 *   slot that was due (BackupAlerts `backup_overdue`: a service that is not running);
 * - warn: a scheduled run was skipped for lack of disk space, scheduled runs were skipped for more than 24 hours because
 *   an operation held the instance lock, or the instance is run by fsa and its backup service never reported;
 * - ok otherwise, saying truthfully what the last run was (a success, a skip that is retried, none yet). An instance not
 *   run by fsa (no runtime file) without rows has its own backup procedure. The instance page of U20 builds on the data.
 */
final readonly class BackupCheck implements HealthCheck
{
    /** Alert => status, worst first. */
    private const array SEVERITY = [
        'backup_failed' => 'fail', 'backup_service_failed' => 'fail', 'backup_overdue' => 'fail',
        'backup_disk_space' => 'warn', 'backup_skipped' => 'warn',
    ];

    public function __construct(private BackupRuns $runs) {}

    public function key(): string
    {
        return 'backup';
    }

    public function run(): HealthResult
    {
        $summary = $this->runs->summary();
        if ($summary['last'] === null && $summary['service'] === null) {
            return config('instance.runtime_path') === null
                ? HealthResult::ok($this->key(), (string) __('platform.health.backup.none'))
                : HealthResult::warn($this->key(), (string) __('platform.health.backup.silent'));
        }
        $details = $this->details($summary);
        foreach (self::SEVERITY as $code => $status) {
            $alert = array_find($summary['alerts'], static fn (array $alert): bool => $alert['code'] === $code);
            if ($alert !== null) {
                $message = (string) __('platform.health.backup.'.substr($code, 7), ['reason' => (string) ($alert['run']['reason'] ?? 'unknown')]);

                return $status === 'fail' ? HealthResult::fail($this->key(), $message, $details) : HealthResult::warn($this->key(), $message, $details);
            }
        }

        return HealthResult::ok($this->key(), $this->quietSummary($summary['last']), $details);
    }

    /** @param  array<string, mixed>|null  $last */
    private function quietSummary(?array $last): string
    {
        return match ($last['status'] ?? null) {
            'succeeded' => __('platform.health.backup.ok').' '.__('platform.health.backup.on_host'),
            'skipped' => (string) __('platform.health.backup.retrying'),
            null => (string) __('platform.health.backup.waiting'),
            default => (string) __('platform.health.backup.ok'),
        };
    }

    /**
     * @param  array{last: array<string, mixed>|null, last_success: array<string, mixed>|null, next_run: string|null}  $summary
     * @return array<string, string|int|CarbonInterface>
     */
    private function details(array $summary): array
    {
        $details = [];
        if ($summary['last'] !== null) {
            $details[(string) __('platform.health.backup.last_run')] = CarbonImmutable::parse((string) $summary['last']['started_at']);
        }
        $success = $summary['last_success'];
        if ($success !== null) {
            $details[(string) __('platform.health.backup.last_success')] = CarbonImmutable::parse((string) $success['started_at']);
            $details[(string) __('platform.health.backup.encrypted')] = (string) __($success['encrypted'] === true ? 'platform.health.backup.yes' : 'platform.health.backup.no');
            if (is_int($success['bytes'] ?? null)) {
                $details[(string) __('platform.health.backup.size')] = Number::withLocale(app()->getLocale(), fn (): string => (string) Number::fileSize($success['bytes'], precision: 1));
            }
            if (($success['missing_files'] ?? 0) > 0) {
                $details[(string) __('platform.health.backup.missing_files')] = (int) $success['missing_files'];
            }
        }
        if ($summary['next_run'] !== null) {
            $details[(string) __('platform.health.backup.next_run')] = CarbonImmutable::parse($summary['next_run']);
        }

        return $details;
    }
}
