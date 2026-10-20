<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * The inputs of `instance:restore-finish` (plan U17, ADR 0085): options plus fsa's stdin document
 * `{"previous_heads": {...}, "previous_epoch": int|null, "from_backup": bool}` (empty stdin: none of them). A manual
 * `fsa rollback` adds `carry`: the current release's SecurityExport, the carry's source instead of `--source` (ADR 0086).
 */
final readonly class FinishRequest
{
    /**
     * @param  array<int, array{seq: int, hash: string}>  $previousHeads
     * @param  array<string, mixed>|null  $carryDocument
     */
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
        public ?CarbonImmutable $backupCreatedAt = null,
        public ?string $journal = null,
        public ?array $carryDocument = null,
    ) {}

    /** `--backup-created-at`: an ISO 8601 time with its zone (the manifest's `created_at`), in UTC; null when absent. */
    public static function backupTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/D', $value) !== 1) {
            throw new InvalidArgumentException('--backup-created-at must be an ISO 8601 time with its zone, e.g. 2026-10-19T12:00:00Z.');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('--backup-created-at must be an ISO 8601 time with its zone, e.g. 2026-10-19T12:00:00Z.');
        }
    }

    /** `--journal`: the fsa journal ID the finish belongs to (hex), null when absent. */
    public static function journalId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^[0-9a-f]{6,64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('--journal must be the hexadecimal ID of an fsa journal.');
        }

        return $value;
    }

    /**
     * @return array{previous_heads: array<int, array{seq: int, hash: string}>, previous_epoch: int|null, from_backup: bool, carry: array<string, mixed>|null}
     *
     * @throws InvalidArgumentException
     */
    public static function stdin(string $json): array
    {
        if (trim($json) === '') {
            return ['previous_heads' => [], 'previous_epoch' => null, 'from_backup' => false, 'carry' => null];
        }

        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('stdin is not a JSON object.');
        }
        $epoch = is_array($document) ? ($document['previous_epoch'] ?? null) : null;
        $carry = is_array($document) ? ($document['carry'] ?? null) : null;
        if (! is_array($document) || ($epoch !== null && (! is_int($epoch) || $epoch < 1)) || ! is_bool($document['from_backup'] ?? false) || ($carry !== null && ! is_array($carry))) {
            throw new InvalidArgumentException('stdin must be {"previous_heads": {...}, "previous_epoch": <int|null>, "from_backup": <bool>}.');
        }

        return ['previous_heads' => RestoredEpoch::heads($document['previous_heads'] ?? null), 'previous_epoch' => $epoch, 'from_backup' => $document['from_backup'] ?? false, 'carry' => $carry];
    }
}
