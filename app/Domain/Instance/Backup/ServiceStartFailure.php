<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Platform\Instance\BackupAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Records that the backup service could not start (R39, ADR 0084), as a run of kind `service` with status `failed`, in
 * the ledger when the instance directory opens and in `backup_runs` when Postgres takes it: `fieldset status` and `/platform`
 * then name the reason (BackupAlerts `backup_service_failed`) instead of only missing backups. Docker restarts the
 * service, so the same failure again adds nothing; a later successful start supersedes it.
 */
final readonly class ServiceStartFailure
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  array<string, string>  $env  the service's environment (FIELDSET_INSTANCE_ROOT, FIELDSET_OWNER_UID, FIELDSET_OWNER_GID)
     * @return bool whether a record was written
     */
    public function record(array $env, string $reason, string $message): bool
    {
        $log = $this->log($env);
        $newest = $log instanceof BackupRunLog ? BackupAlerts::service($log->recent(PHP_INT_MAX)) : $this->newestRow();
        if (($newest['status'] ?? null) === 'failed' && ($newest['reason'] ?? null) === $reason) {
            return false;
        }
        $now = CarbonImmutable::now('UTC')->toIso8601ZuluString();
        new BackupRunRecorder($log, $this->db)->record([
            'kind' => BackupAlerts::SERVICE, 'status' => 'failed', 'reason' => mb_substr($reason, 0, 64), 'message' => $message,
            'started_at' => $now, 'finished_at' => $now, 'encrypted' => false,
        ]);

        return true;
    }

    /** @param  array<string, string>  $env */
    private function log(array $env): ?BackupRunLog
    {
        $id = static fn (string $key): ?int => preg_match('/^\d{1,10}$/D', $env[$key] ?? '') === 1 ? (int) $env[$key] : null;

        try {
            $directory = InstanceDirectory::open(($env['FIELDSET_INSTANCE_ROOT'] ?? '') !== '' ? $env['FIELDSET_INSTANCE_ROOT'] : BackupConfig::INSTANCE_ROOT);

            return new BackupRunLog($directory, new BackupStore($directory, $id('FIELDSET_OWNER_UID'), $id('FIELDSET_OWNER_GID')));
        } catch (Throwable) {
            return null; // the table alone then
        }
    }

    /** @return array<string, mixed>|null */
    private function newestRow(): ?array
    {
        try {
            $row = $this->db->table('backup_runs')->where('kind', BackupAlerts::SERVICE)->orderByDesc('started_at')->first();

            return $row === null ? null : (array) $row;
        } catch (Throwable) {
            return null;
        }
    }
}
