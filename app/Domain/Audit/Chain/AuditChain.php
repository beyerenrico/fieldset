<?php

declare(strict_types=1);

namespace App\Domain\Audit\Chain;

use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Tamper evidence for the audit log (BUILD_PROMPT §8.11 "hash chain", ADR 0038). Each organization has one
 * chain (platform entries without an organization share chain 0). An entry is sealed after the transaction
 * that wrote it commits: under a transaction-scoped advisory lock per chain it gets the next sequence
 * number, the previous entry's hash and its own hash over its stored values. Sealing in commit order keeps
 * long transactions from serializing each other (only the short sealing transactions queue per chain), and
 * a rolled-back entry never enters the chain. Entries whose sealing did not run (crash between commit and
 * seal) are sealed by `audit:seal`. A chain that retention pruned completely continues after its anchor.
 */
final class AuditChain
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** First key of pg_advisory_xact_lock(int, int); the second is the chain. */
    private const int LOCK_NAMESPACE = 830_011;

    /** Stored columns covered by the hash, in this order. */
    public const array FIELDS = [
        'id', 'organization_id', 'log_name', 'description', 'subject_type', 'subject_id', 'event',
        'causer_type', 'causer_id', 'attribute_changes', 'properties', 'actor_type', 'ip_address',
        'user_agent', 'request_id', 'created_at',
    ];

    /** Seals one entry; false when it is already sealed or does not exist. */
    public function seal(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $pending = DB::table('activity_log')->where('id', $id)->whereNull('hash')->first(['organization_id']);
            if (! $pending instanceof stdClass) {
                return false;
            }
            $chain = self::chainOf($pending->organization_id);
            DB::select('select pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $chain % 2_147_483_647]);

            $entry = DB::table('activity_log')->where('id', $id)->whereNull('hash')->first();
            if (! $entry instanceof stdClass) {
                return false; // sealed by a concurrent sealer while this one waited for the lock
            }
            [$seq, $prev] = $this->next($chain);

            // audit_seal(): the one UPDATE the append-only trigger allows (SECURITY DEFINER, ADR 0038).
            return (bool) DB::scalar('SELECT audit_seal(?, ?, ?, ?)', [$id, $seq, $prev, self::hash($prev, $seq, $entry)]);
        });
    }

    /** Seals after commit and never fails the caller: a missed seal is caught up by `audit:seal`. */
    public function sealQuietly(int $id): void
    {
        try {
            $this->seal($id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Seals unsealed entries, oldest first. */
    public function sealPending(int $limit = 5000): int
    {
        $sealed = 0;
        foreach (DB::table('activity_log')->whereNull('hash')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            try {
                $sealed += $this->seal((int) $id) ? 1 : 0;
            } catch (Throwable $e) {
                report($e); // one entry that cannot be sealed must not block the others
            }
        }

        return $sealed;
    }

    /** The hash of an entry: previous hash, sequence number and the stored values (as strings). */
    public static function hash(string $prev, int $seq, stdClass $entry): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $entry->{$field} ?? null;
            $values[] = $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return hash('sha256', $prev."\n".$seq."\n".json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Chain of an entry: its organization, or 0 for platform entries. */
    public static function chainOf(mixed $organizationId): int
    {
        return $organizationId === null ? 0 : (int) $organizationId;
    }

    /**
     * Position and previous hash of a chain's next entry: after its head; for a chain that retention pruned
     * completely, after its anchor (the last deleted entry), never seq 1 again; otherwise the genesis.
     *
     * @return array{0: int, 1: string}
     */
    private function next(int $chain): array
    {
        $head = $this->head($chain);
        if ($head instanceof stdClass) {
            return [(int) $head->chain_seq + 1, (string) $head->hash];
        }
        $anchor = PruneAnchors::forSealing()[$chain] ?? null;

        return $anchor === null ? [1, self::GENESIS] : [$anchor['seq'] + 1, $anchor['hash']];
    }

    /** Latest sealed entry of a chain (served by activity_log_chain_idx). */
    private function head(int $chain): ?stdClass
    {
        return DB::table('activity_log')
            ->whereRaw('COALESCE(organization_id, 0) = ?', [$chain])
            ->whereNotNull('chain_seq')
            ->orderByRaw('COALESCE(organization_id, 0) desc, chain_seq desc')
            ->first(['chain_seq', 'hash']);
    }
}
