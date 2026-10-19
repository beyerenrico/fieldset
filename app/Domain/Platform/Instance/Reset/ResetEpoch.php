<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Reset;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Platform\Instance\Restore\RestoreRefused;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The audit epoch of the database `fsa reset` created (plan U23, KTD26, R43, R68, ADR 0088): right after the migrations,
 * before anything else writes to it, the epoch goes one above the ledger's seed with the heads of the chains the reset
 * replaced (the old database's final heads from `backups/ledger.log`), and `instance.reset` by the instance owner at the
 * command line becomes its first entry, naming both epochs, the old heads and the safety backup or audit export that
 * kept the old data. One transaction.
 *
 * It runs once per database (LEARNINGS 138): an epoch row next to an `instance.reset` entry means an earlier attempt of
 * this reset began it, so a resumed reset gets that epoch back (`began: false`) instead of a second one. A database that
 * already holds other audit entries is not the reset's fresh one and is refused (`database_not_fresh`).
 */
final readonly class ResetEpoch
{
    public const string EVENT = 'instance.reset';

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<int, array{seq: int, hash: string}>  $previousHeads
     * @return array{epoch: int, previous_epoch: int|null, activity_id: mixed, began: bool}
     *
     * @throws RestoreRefused `database_not_fresh`
     */
    public function begin(int $seed, array $previousHeads, ?int $previousEpoch, ?string $safetyBackup, ?string $auditExport, bool $demo, ?int $hostUid): array
    {
        return DB::transaction(function () use ($seed, $previousHeads, $previousEpoch, $safetyBackup, $auditExport, $demo, $hostUid): array {
            DB::statement('LOCK TABLE audit_chain_epochs IN EXCLUSIVE MODE');
            $began = DB::table('activity_log')->where('event', self::EVENT)->orderBy('id')->value('id');
            if (DB::table('audit_chain_epochs')->exists() && $began !== null) {
                return ['epoch' => ChainEpoch::current(), 'previous_epoch' => $previousEpoch, 'activity_id' => $began, 'began' => false];
            }
            if (DB::table('audit_chain_epochs')->exists() || DB::table('activity_log')->exists()) {
                throw new RestoreRefused('database_not_fresh', 'The database already holds audit entries or epochs; the reset begins the epoch only in the database it created.', 'Run `fsa reset` again: until the epoch began it recreates the database first.');
            }
            $epoch = ChainEpoch::begin('reset', $seed, $previousHeads);
            $activity = $this->audit->cli(self::EVENT, null, [
                'epoch' => $epoch, 'previous_epoch' => $previousEpoch, 'previous_heads' => (object) $previousHeads,
                'safety_backup' => $safetyBackup, 'audit_export' => $auditExport, 'demo' => $demo,
            ], $hostUid);

            return ['epoch' => $epoch, 'previous_epoch' => $previousEpoch, 'activity_id' => $activity instanceof Model ? $activity->getKey() : null, 'began' => true];
        });
    }
}
