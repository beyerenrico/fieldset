<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Audit\Chain\ChainHeads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
     * With `$journal` (fieldset's journal ID, recorded in the entry) a second run for the same journal writes nothing: a
     * finish whose one-off committed before fieldset recorded the phase (`--from-backup` resumes it on the live database)
     * returns the epoch it began (`epoch_repeated`) instead of a second epoch and a second entry (review LOW 6).
     *
     * @param  array<int, array{seq: int, hash: string}>  $previousHeads
     * @return array{epoch: int, previous_epoch: int|null, activity_id: mixed, restored_heads: array<int|string, mixed>, epoch_repeated: bool}
     */
    public function begin(string $reason, int $seed, array $previousHeads, ?int $previousEpoch, ?string $backup, bool $fromBackup, ?int $hostUid, ?string $journal = null): array
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('--reason must be restored or rolled_back.');
        }
        $event = $reason === 'rolled_back' ? 'instance.rolled_back' : 'instance.restored';
        $earlier = $journal === null ? null : $this->earlier($event, $journal);
        if ($earlier !== null) {
            return $earlier;
        }
        $restoredHeads = ChainHeads::read();
        $epoch = ChainEpoch::begin($reason, $seed, $previousHeads);
        $properties = [
            'backup' => $backup, 'epoch' => $epoch, 'previous_epoch' => $previousEpoch, 'from_backup' => $fromBackup,
            'previous_heads' => (object) $previousHeads, 'restored_heads' => (object) $restoredHeads, ...($journal === null ? [] : ['journal' => $journal]),
        ];
        $activity = $this->audit->cli($event, null, $properties, $hostUid);

        return [
            'epoch' => $epoch, 'previous_epoch' => $previousEpoch,
            'activity_id' => $activity instanceof Model ? $activity->getKey() : null, 'restored_heads' => $restoredHeads, 'epoch_repeated' => false,
        ];
    }

    /** @return array{epoch: int, previous_epoch: int|null, activity_id: mixed, restored_heads: array<int|string, mixed>, epoch_repeated: true}|null */
    private function earlier(string $event, string $journal): ?array
    {
        $entry = DB::table('activity_log')->where('event', $event)->whereRaw("properties::jsonb ->> 'journal' = ?", [$journal])->orderBy('id')->first(['id', 'properties']);
        if ($entry === null) {
            return null;
        }
        $properties = json_decode((string) $entry->properties, true);
        $properties = is_array($properties) ? $properties : [];

        return [
            'epoch' => (int) ($properties['epoch'] ?? 0), 'previous_epoch' => is_int($properties['previous_epoch'] ?? null) ? $properties['previous_epoch'] : null,
            'activity_id' => $entry->id, 'restored_heads' => is_array($properties['restored_heads'] ?? null) ? $properties['restored_heads'] : [], 'epoch_repeated' => true,
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
