<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Audit\Chain\ChainHeads;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The new audit epoch of a restored database (plan KTD26, R68, ADR 0085): before anything else writes to it, the epoch
 * goes one above the restored database's own and above the ledger's seed, with the heads of the chains the restore
 * replaced (the pre-restore database's final heads from `backups/ledger.log`), and the `instance.restored` entry by the
 * instance owner at the command line (`instance.rolled_back` for U18's rollback) becomes the epoch's first entry. It
 * names the backup, both epochs, the replaced heads and the heads the restored database came with.
 */
final readonly class RestoredEpoch
{
    public const array REASONS = ['restored', 'rolled_back'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<int, array{seq: int, hash: string}>  $previousHeads
     * @return array{epoch: int, previous_epoch: int|null, activity_id: mixed, restored_heads: array<int, array{seq: int, hash: string}>}
     */
    public function begin(string $reason, int $seed, array $previousHeads, ?int $previousEpoch, ?string $backup, bool $fromBackup, ?int $hostUid): array
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('--reason must be restored or rolled_back.');
        }
        $restoredHeads = ChainHeads::read();
        $epoch = ChainEpoch::begin($reason, $seed, $previousHeads);
        $properties = [
            'backup' => $backup, 'epoch' => $epoch, 'previous_epoch' => $previousEpoch, 'from_backup' => $fromBackup,
            'previous_heads' => (object) $previousHeads, 'restored_heads' => (object) $restoredHeads,
        ];
        $activity = $this->audit->cli($reason === 'rolled_back' ? 'instance.rolled_back' : 'instance.restored', null, $properties, $hostUid);

        return [
            'epoch' => $epoch, 'previous_epoch' => $previousEpoch,
            'activity_id' => $activity instanceof Model ? $activity->getKey() : null, 'restored_heads' => $restoredHeads,
        ];
    }

    /**
     * The heads of stdin's JSON (`{"<chain>": {"seq": int, "hash": str}}`), validated: a malformed head is refused rather
     * than recorded.
     *
     * @return array<int, array{seq: int, hash: string}>
     */
    public static function heads(mixed $heads): array
    {
        if ($heads === null) {
            return [];
        }
        if (! is_array($heads)) {
            throw new InvalidArgumentException('previous_heads must be an object of chain heads.');
        }
        $parsed = [];
        foreach ($heads as $chain => $head) {
            $hash = is_array($head) ? ($head['hash'] ?? null) : null;
            if (preg_match('/^\d{1,19}$/D', (string) $chain) !== 1 || ! is_array($head) || ! is_int($head['seq'] ?? null) || ! is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                throw new InvalidArgumentException("previous_heads[{$chain}] must be {\"seq\": <int>, \"hash\": <sha256 hex>}.");
            }
            $parsed[(int) $chain] = ['seq' => $head['seq'], 'hash' => $hash];
        }
        ksort($parsed);

        return $parsed;
    }
}
