<?php

declare(strict_types=1);

namespace App\Domain\Audit\Chain;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The audit chain epoch (plan KTD26, R68; ADR 0084 amending ADR 0038). A database that restore, rollback or reset put
 * back to an earlier point starts a new epoch before anything else writes to it, so heads shipped outside the database
 * stay verifiable: they are compared within their epoch only. The epoch is reported with the heads (`audit:verify
 * --heads`, a backup's `audit-heads.json`). Epoch 1 has no row; `begin()` records each later one with the heads of the
 * chains it replaced, seeded from the host-side ledger so a recreated database never reuses an epoch number.
 */
final readonly class ChainEpoch
{
    public const array REASONS = ['restored', 'rolled_back', 'reset'];

    public static function current(?ConnectionInterface $db = null): int
    {
        $max = ($db ?? DB::connection())->table('audit_chain_epochs')->max('epoch');

        return is_numeric($max) ? max(1, (int) $max) : 1;
    }

    /**
     * Starts the next epoch: one above the current one and above `$seed` (the highest epoch the ledger knows).
     *
     * @param  array<int, array{seq: int, hash: string}>  $previousHeads  the heads of the chains before the operation
     */
    public static function begin(string $reason, int $seed = 0, array $previousHeads = [], ?ConnectionInterface $db = null): int
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown epoch reason [{$reason}].");
        }
        $db ??= DB::connection();

        return $db->transaction(function () use ($db, $reason, $seed, $previousHeads): int {
            $db->statement('LOCK TABLE audit_chain_epochs IN EXCLUSIVE MODE');
            $epoch = max(self::current($db), $seed) + 1;
            $db->table('audit_chain_epochs')->insert([
                'epoch' => $epoch, 'reason' => $reason,
                'previous_heads' => json_encode((object) $previousHeads, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            return $epoch;
        });
    }
}
