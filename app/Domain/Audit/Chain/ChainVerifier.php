<?php

declare(strict_types=1);

namespace App\Domain\Audit\Chain;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Recomputes the audit hash chains (`audit:verify`, ADR 0038). Per chain, entries in sequence order must
 * form an unbroken run: each hash matches the stored values, each prev_hash is the previous entry's hash and
 * the sequence has no gaps. A chain with an anchor (the cut of the latest retention prune, in the newest sealed
 * `audit.pruned` entry) must start right after it, also when it was pruned completely and continued; any
 * other chain starts at seq 1 from the genesis hash. A chain left without entries reports its anchor as head,
 * so comparing the heads with stored copies shows the removal of its newer entries. Read-only.
 */
final readonly class ChainVerifier
{
    private const int CHUNK = 1000;

    private const int MAX_PROBLEMS_PER_CHAIN = 20;

    public function verify(?int $chain = null): ChainReport
    {
        $anchors = PruneAnchors::latest();
        $chains = $chain === null ? $this->chains(array_keys($anchors)) : [$chain];
        $problems = [];
        $heads = [];
        $checked = 0;

        foreach ($chains as $key) {
            [$count, $found, $head] = $this->verifyChain($key, $anchors[$key] ?? null);
            $checked += $count;
            array_push($problems, ...$found);
            if ($head !== null) {
                $heads[$key] = $head;
            }
        }

        // Entries that should long be sealed have no tamper evidence yet (sealing broken or not scheduled).
        $unsealed = DB::table('activity_log')->whereNull('hash')
            ->when($chain !== null, fn ($q) => $q->whereRaw('COALESCE(organization_id, 0) = ?', [$chain]))
            ->where('created_at', '<', now()->subMinutes(10))->count();
        if ($unsealed > 0) {
            $problems[] = "{$unsealed} entries older than ten minutes are not sealed (run audit:seal)";
        }

        return new ChainReport(count($chains), $checked, $unsealed, $problems, $heads);
    }

    /**
     * Chains with sealed entries plus chains known only from their anchor (pruned completely).
     *
     * @param  list<int>  $anchored
     * @return list<int>
     */
    private function chains(array $anchored): array
    {
        $chains = DB::table('activity_log')->whereNotNull('chain_seq')
            ->selectRaw('DISTINCT COALESCE(organization_id, 0) AS chain')->orderBy('chain')->pluck('chain')->all();
        $chains = array_unique([...array_map(static fn (mixed $c): int => is_numeric($c) ? (int) $c : 0, $chains), ...$anchored]);
        sort($chains);

        return $chains;
    }

    /**
     * @param  array{seq: int, hash: string}|null  $anchor
     * @return array{0: int, 1: list<string>, 2: array{seq: int, hash: string}|null}
     */
    private function verifyChain(int $chain, ?array $anchor): array
    {
        $problems = [];
        $previous = null;
        $count = 0;
        $after = 0;

        do {
            $rows = DB::table('activity_log')->whereRaw('COALESCE(organization_id, 0) = ?', [$chain])
                ->where('chain_seq', '>', $after)->orderByRaw('COALESCE(organization_id, 0), chain_seq')
                ->limit(self::CHUNK)->get();
            foreach ($rows as $row) {
                /** @var stdClass $row */
                $problem = $this->check($chain, $row, $previous, $anchor);
                if ($problem !== null && count($problems) < self::MAX_PROBLEMS_PER_CHAIN) {
                    $problems[] = $problem;
                }
                $previous = $row;
                $after = (int) $row->chain_seq;
                $count++;
            }
        } while ($rows->count() === self::CHUNK);

        $head = $previous === null ? $anchor : ['seq' => (int) $previous->chain_seq, 'hash' => (string) $previous->hash];

        return [$count, $problems, $head];
    }

    /** @param  array{seq: int, hash: string}|null  $anchor */
    private function check(int $chain, stdClass $row, ?stdClass $previous, ?array $anchor): ?string
    {
        $seq = (int) $row->chain_seq;
        if (AuditChain::hash((string) $row->prev_hash, $seq, $row) !== $row->hash) {
            return "chain {$chain}: entry #{$row->id} (seq {$seq}) does not match its hash";
        }
        if (! $previous instanceof stdClass) {
            return $this->checkStart($chain, $row, $anchor);
        }
        if ($seq !== (int) $previous->chain_seq + 1) {
            return "chain {$chain}: entries missing between seq {$previous->chain_seq} and {$seq}";
        }

        return $row->prev_hash === $previous->hash ? null : "chain {$chain}: entry #{$row->id} (seq {$seq}) does not link to seq {$previous->chain_seq}";
    }

    /** @param  array{seq: int, hash: string}|null  $anchor */
    private function checkStart(int $chain, stdClass $row, ?array $anchor): ?string
    {
        $seq = (int) $row->chain_seq;
        if ($anchor !== null) {
            return $anchor['seq'] === $seq - 1 && $anchor['hash'] === $row->prev_hash
                ? null : "chain {$chain}: first entry #{$row->id} (seq {$seq}) does not continue from the retention cut at seq {$anchor['seq']}";
        }
        if ($seq === 1) {
            return $row->prev_hash === AuditChain::GENESIS ? null : "chain {$chain}: first entry #{$row->id} does not start the chain";
        }

        return "chain {$chain}: entries before seq {$seq} are missing (no matching retention prune)";
    }
}
