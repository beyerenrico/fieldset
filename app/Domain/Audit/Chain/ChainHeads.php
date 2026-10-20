<?php

declare(strict_types=1);

namespace App\Domain\Audit\Chain;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The head of every audit hash chain without walking the chains (plan U16, KTD16): the sealed entry with the highest
 * sequence number per chain, or the chain's retention anchor when pruning emptied it. For intact chains these are the
 * heads `audit:verify --heads` reports after its full check (ChainVerifier). Backups read them inside the exported
 * snapshot their dump comes from, so `audit-heads.json` matches the dump exactly; one index scan
 * (`activity_log_chain_idx`) per chain, cheap enough for the backup service's memory slot.
 */
final readonly class ChainHeads
{
    /** @return array<int, array{seq: int, hash: string}> chain number => head, sorted by chain */
    public static function read(?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();
        $heads = PruneAnchors::latest($db);
        $rows = $db->select(<<<'SQL'
            SELECT DISTINCT ON (COALESCE(organization_id, 0)) COALESCE(organization_id, 0) AS chain, chain_seq, hash
            FROM activity_log WHERE chain_seq IS NOT NULL
            ORDER BY COALESCE(organization_id, 0), chain_seq DESC
            SQL);
        foreach ($rows as $row) {
            $heads[(int) $row->chain] = ['seq' => (int) $row->chain_seq, 'hash' => (string) $row->hash];
        }
        ksort($heads);

        return $heads;
    }
}
