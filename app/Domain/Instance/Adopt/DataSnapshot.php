<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * What a database held at one moment, as `fieldset adopt` compares it (KTD20 step 5, plan U19, ADR 0087): the row counts of the
 * main tables (the restore drill's, plus every audit entry), the head of every audit hash chain (the sealed entry with the
 * highest sequence number per organization chain, as ChainHeads reads it) and the migration set's size and last name.
 * The copy must equal the 1.0 backup exactly; after adopt the same snapshot is the baseline uninstall counts the writes
 * since adopt from.
 */
final readonly class DataSnapshot
{
    /** DrillReport::COUNTED plus the audit log. */
    public const array COUNTED = ['organizations', 'users', 'forms', 'form_versions', 'submissions', 'attachments', 'renditions', 'mail_messages', 'activity_log'];

    /**
     * @param  array<string, int>  $counts  table => rows
     * @param  array<string, array{seq: int, hash: string}>  $heads  chain (organization ID, 0 for the platform) => head
     */
    public function __construct(
        public array $counts,
        public array $heads,
        public int $migrations,
        public ?string $lastMigration,
        public ?string $postgres = null,
    ) {}

    /**
     * The one JSON line DatabaseProbe::SNAPSHOT prints.
     *
     * @throws InstanceException exit 4 for anything else
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode(trim($json), true);
        if (! is_array($data) || ! is_array($data['counts'] ?? null) || ! is_numeric($data['migrations'] ?? null)) {
            throw new InstanceException('The database snapshot could not be read.', ExitCode::HealthCheckFailed, 'snapshot_failed', 'Read `docker logs` of the Postgres container; nothing was changed by the snapshot.');
        }

        return self::fromArray($data);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $counts = [];
        foreach (is_array($data['counts'] ?? null) ? $data['counts'] : [] as $table => $count) {
            if (is_numeric($count)) {
                $counts[(string) $table] = (int) $count;
            }
        }
        $heads = [];
        foreach (is_array($data['heads'] ?? null) ? $data['heads'] : [] as $chain => $head) {
            if (is_array($head) && is_numeric($head['seq'] ?? null) && is_string($head['hash'] ?? null)) {
                $heads[(string) $chain] = ['seq' => (int) $head['seq'], 'hash' => $head['hash']];
            }
        }
        ksort($counts);
        ksort($heads);

        return new self($counts, $heads, (int) ($data['migrations'] ?? 0), is_string($data['last_migration'] ?? null) ? $data['last_migration'] : null, is_string($data['postgres'] ?? null) ? $data['postgres'] : null);
    }

    /** @return array{counts: array<string, int>, heads: array<string, array{seq: int, hash: string}>, migrations: int, last_migration: string|null, postgres: string|null} */
    public function toArray(): array
    {
        return ['counts' => $this->counts, 'heads' => $this->heads, 'migrations' => $this->migrations, 'last_migration' => $this->lastMigration, 'postgres' => $this->postgres];
    }

    /**
     * Where this snapshot differs from the expected one: counts, chain heads, the migration set (not the server version).
     *
     * @return list<string> human-readable differences, empty when equal
     */
    public function differences(self $expected): array
    {
        $problems = [];
        foreach ([...array_keys($expected->counts), ...array_keys($this->counts)] as $table) {
            [$want, $have] = [$expected->counts[$table] ?? null, $this->counts[$table] ?? null];
            if ($want !== $have) {
                $problems[$table] = "{$table}: ".($have ?? 'missing').' rows instead of '.($want ?? 'none');
            }
        }
        foreach ([...array_keys($expected->heads), ...array_keys($this->heads)] as $chain) {
            if (($expected->heads[$chain] ?? null) !== ($this->heads[$chain] ?? null)) {
                $problems['chain '.$chain] = "audit chain {$chain}: head ".$this->head($this->heads[$chain] ?? null).' instead of '.$this->head($expected->heads[$chain] ?? null);
            }
        }
        if ($expected->migrations !== $this->migrations || $expected->lastMigration !== $this->lastMigration) {
            $problems['migrations'] = "migrations: {$this->migrations} (last {$this->lastMigration}) instead of {$expected->migrations} (last {$expected->lastMigration})";
        }

        return array_values($problems);
    }

    /**
     * The writes made since a baseline: audit entries sealed since (per chain, the sequence numbers it moved on) and the
     * change of every counted table.
     *
     * @return array{audit_entries: int, rows: array<string, int>, any: bool}
     */
    public function writesSince(self $baseline): array
    {
        $audit = 0;
        foreach ($this->heads as $chain => $head) {
            $audit += max(0, $head['seq'] - ($baseline->heads[$chain]['seq'] ?? 0));
        }
        $rows = [];
        foreach ($this->counts as $table => $count) {
            $delta = $count - ($baseline->counts[$table] ?? 0);
            if ($delta !== 0) {
                $rows[$table] = $delta;
            }
        }

        return ['audit_entries' => $audit, 'rows' => $rows, 'any' => $audit > 0 || $rows !== []];
    }

    /** @param  array{seq: int, hash: string}|null  $head */
    private function head(?array $head): string
    {
        return $head === null ? 'none' : $head['seq'].'/'.substr($head['hash'], 0, 12);
    }
}
