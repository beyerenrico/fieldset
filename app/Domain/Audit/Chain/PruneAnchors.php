<?php

declare(strict_types=1);

namespace App\Domain\Audit\Chain;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Where retention pruning cut each hash chain: the sequence number and hash of the last deleted entry per
 * chain. Every `audit.pruned` entry stores the anchors of all chains (new cuts plus the previous anchors
 * carried forward), so the newest one is enough for `audit:verify` even after older prune entries expired.
 */
final class PruneAnchors
{
    /**
     * The anchors `audit:verify` checks against. Only sealed prune entries count: a row inserted outside the
     * application is not part of the chain yet. `$db`: another connection, e.g. the backup's exported snapshot.
     *
     * @return array<int, array{seq: int, hash: string}>
     */
    public static function latest(?ConnectionInterface $db = null): array
    {
        return self::read(sealedOnly: true, db: $db);
    }

    /**
     * The anchors sealing continues from when a chain has no entries left. The newest prune entry counts even
     * while it is unsealed: it commits together with its cuts and may be the very entry being sealed (the
     * platform chain after a full prune), or be sealed only after the first new entry of a pruned chain.
     *
     * @return array<int, array{seq: int, hash: string}>
     */
    public static function forSealing(): array
    {
        return self::read(sealedOnly: false);
    }

    /**
     * The previous anchors with the cuts audit_prune() reported (rows: pruned_chain, last_seq, last_hash).
     *
     * @param  array<int, array{seq: int, hash: string}>  $previous
     * @param  array<array-key, mixed>  $cuts
     * @return array<int, array{seq: int, hash: string}>
     */
    public static function merge(array $previous, array $cuts): array
    {
        $anchors = $previous;
        foreach ($cuts as $cut) {
            if ($cut instanceof stdClass && is_numeric($cut->last_seq ?? null) && is_string($cut->last_hash ?? null)) {
                $anchors[(int) $cut->pruned_chain] = ['seq' => (int) $cut->last_seq, 'hash' => $cut->last_hash];
            }
        }
        ksort($anchors);

        return $anchors;
    }

    /** @return array<int, array{seq: int, hash: string}> */
    private static function read(bool $sealedOnly, ?ConnectionInterface $db = null): array
    {
        $properties = ($db ?? DB::connection())->table('activity_log')->where('event', 'audit.pruned')->whereNull('organization_id')
            ->when($sealedOnly, fn ($query) => $query->whereNotNull('hash'))->orderByDesc('id')->value('properties');
        $decoded = is_string($properties) ? json_decode($properties, true) : null;

        return self::normalize(is_array($decoded) ? ($decoded['anchors'] ?? []) : []);
    }

    /**
     * @return array<int, array{seq: int, hash: string}>
     */
    private static function normalize(mixed $anchors): array
    {
        $clean = [];
        foreach (is_array($anchors) ? $anchors : [] as $chain => $anchor) {
            if (is_array($anchor) && is_numeric($anchor['seq'] ?? null) && is_string($anchor['hash'] ?? null)) {
                $clean[(int) $chain] = ['seq' => (int) $anchor['seq'], 'hash' => $anchor['hash']];
            }
        }

        return $clean;
    }
}
