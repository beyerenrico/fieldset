<?php

declare(strict_types=1);

namespace App\Domain\Audit\Retention;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\PruneAnchors;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Audit retention (BUILD_PROMPT §8.11, ADR 0038): deletes entries older than `audit.retention_days`
 * (default 365, at least 30) through the database function audit_prune(), the only path the append-only
 * trigger lets through. One transaction: the delete and the platform-level `audit.pruned` entry (cutoff,
 * count, and the chain anchors `audit:verify` needs — the previous ones carried forward plus the cuts the
 * function reported) commit together, so a killed run never leaves a cut without its anchor.
 */
final readonly class PruneAuditLog
{
    public const int DEFAULT_DAYS = 365;

    /** audit_prune() refuses to delete entries younger than this. */
    public const int MIN_DAYS = 30;

    public function __construct(private AuditLogger $audit, private TenantContext $tenant) {}

    public static function retentionDays(): int
    {
        return max(self::MIN_DAYS, (int) config('audit.retention_days', self::DEFAULT_DAYS));
    }

    /** @return int number of deleted entries */
    public function handle(?int $days = null): int
    {
        $days = max(self::MIN_DAYS, $days ?? self::retentionDays());
        $cutoff = now()->subDays($days)->toDateTimeString();

        return DB::transaction(function () use ($cutoff, $days): int {
            $previous = PruneAnchors::latest(); // read first: the newest audit.pruned entry may be pruned now
            $cuts = DB::select('SELECT * FROM audit_prune(?::timestamp)', [$cutoff]);
            $deleted = array_sum(array_map(static fn (mixed $cut): int => $cut instanceof stdClass && is_numeric($cut->deleted ?? null) ? (int) $cut->deleted : 0, $cuts));
            if ($deleted > 0) {
                $this->recordPlatformEntry($cutoff, $days, $deleted, PruneAnchors::merge($previous, $cuts));
            }

            return $deleted;
        });
    }

    /** @param  array<int, array{seq: int, hash: string}>  $anchors */
    private function recordPlatformEntry(string $cutoff, int $days, int $deleted, array $anchors): void
    {
        $previous = $this->tenant->id();
        $this->tenant->clear();

        try {
            $this->audit->system('audit.pruned', null, [
                'before' => $cutoff, 'retention_days' => $days, 'deleted' => $deleted, 'anchors' => $anchors,
            ]);
        } finally {
            if ($previous !== null) {
                $this->tenant->set($previous);
            }
        }
    }
}
