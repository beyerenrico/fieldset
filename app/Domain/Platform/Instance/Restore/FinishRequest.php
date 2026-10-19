<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use InvalidArgumentException;
use JsonException;

/**
 * The inputs of `instance:restore-finish` (plan U17, ADR 0085): options plus fsa's stdin document
 * `{"previous_heads": {...}, "previous_epoch": int|null, "from_backup": bool}` (empty stdin: none of them).
 */
final readonly class FinishRequest
{
    /** @param  array<int, array{seq: int, hash: string}>  $previousHeads */
    public function __construct(
        public ?string $source,
        public ?string $backup,
        public int $epochSeed,
        public ?int $actorUid,
        public string $reason,
        public bool $carry,
        public string $backupRole,
        public array $previousHeads = [],
        public ?int $previousEpoch = null,
        public bool $fromBackup = false,
    ) {}

    /**
     * @return array{previous_heads: array<int, array{seq: int, hash: string}>, previous_epoch: int|null, from_backup: bool}
     *
     * @throws InvalidArgumentException
     */
    public static function stdin(string $json): array
    {
        if (trim($json) === '') {
            return ['previous_heads' => [], 'previous_epoch' => null, 'from_backup' => false];
        }

        try {
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('stdin is not a JSON object.');
        }
        $epoch = is_array($document) ? ($document['previous_epoch'] ?? null) : null;
        if (! is_array($document) || ($epoch !== null && (! is_int($epoch) || $epoch < 1)) || ! is_bool($document['from_backup'] ?? false)) {
            throw new InvalidArgumentException('stdin must be {"previous_heads": {...}, "previous_epoch": <int|null>, "from_backup": <bool>}.');
        }

        return ['previous_heads' => RestoredEpoch::heads($document['previous_heads'] ?? null), 'previous_epoch' => $epoch, 'from_backup' => $document['from_backup'] ?? false];
    }
}
