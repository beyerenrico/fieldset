<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Records a backup run twice (R39, ADR 0084): in the ledger `backups/runs.log` (always; the daemon's memory and the
 * source of `fsa status`) and in the `backup_runs` table, through the backup role that may insert only there
 * (`/platform` and the operator alerts read it). A run the table cannot take, because Postgres is down or the role is
 * missing, is still in the ledger with `recorded: false`.
 */
final readonly class BackupRunRecorder
{
    private const array COLUMNS = ['kind', 'status', 'backup', 'started_at', 'finished_at', 'bytes', 'encrypted', 'reason', 'message', 'free_bytes', 'missing_files', 'schedule', 'time_zone'];

    /** @param  BackupRunLog|null  $log  null when the instance directory cannot be opened (a failed service start) */
    public function __construct(private ?BackupRunLog $log, private ConnectionInterface $db) {}

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed> the run as the ledger holds it
     */
    public function record(array $run): array
    {
        $row = array_intersect_key($run, array_flip(self::COLUMNS));
        $row['message'] = is_string($row['message'] ?? null) ? mb_substr($row['message'], 0, 500) : null;

        try {
            $this->db->table('backup_runs')->insert($row);
            $recorded = true;
        } catch (Throwable) {
            $recorded = false;
        }
        $run = [...$run, 'recorded' => $recorded];
        $line = $run;
        unset($line['result']); // the details go to the caller (fsa backup's envelope), not into every ledger line
        $this->log?->append($line);

        return $run;
    }
}
