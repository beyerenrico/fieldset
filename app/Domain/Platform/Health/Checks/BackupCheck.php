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
 * Backups of an fsa instance (R39, ADR 0084), from the `backup_runs` table the backup service writes: fail when the
 * last run failed, warn when scheduled runs were skipped for more than 24 hours (BackupAlerts), ok otherwise, also
 * without any run (an instance not run by fsa has its own backup procedure). A persistent warning on /platform, so a
 * failure is visible while mail is captured; the instance page of plan U20 builds on the same data.
 */
final readonly class BackupCheck implements HealthCheck
{
    public function __construct(private BackupRuns $runs) {}

    public function key(): string
    {
        return 'backup';
    }

    public function run(): HealthResult
    {
        $summary = $this->runs->summary();
        $last = $summary['last'];
        if ($last === null) {
            return HealthResult::ok($this->key(), (string) __('platform.health.backup.none'));
        }
        $details = $this->details($last, $summary['last_success']);
        $codes = array_column($summary['alerts'], 'code');
        if (in_array('backup_failed', $codes, true)) {
            $failed = $summary['alerts'][array_search('backup_failed', $codes, true)]['run'] ?? [];

            return HealthResult::fail($this->key(), (string) __('platform.health.backup.failed', ['reason' => (string) ($failed['reason'] ?? 'unknown')]), $details);
        }
        if (in_array('backup_skipped', $codes, true)) {
            return HealthResult::warn($this->key(), (string) __('platform.health.backup.skipped'), $details);
        }

        return HealthResult::ok($this->key(), __('platform.health.backup.ok').' '.__('platform.health.backup.on_host'), $details);
    }

    /**
     * @param  array<string, mixed>  $last
     * @param  array<string, mixed>|null  $success
     * @return array<string, string|int|CarbonInterface>
     */
    private function details(array $last, ?array $success): array
    {
        $details = [(string) __('platform.health.backup.last_run') => CarbonImmutable::parse((string) $last['started_at'])];
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

        return $details;
    }
}
