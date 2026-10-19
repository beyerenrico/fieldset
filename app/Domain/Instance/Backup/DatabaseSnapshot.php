<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Audit\Chain\ChainHeads;
use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * One exported Postgres snapshot for a whole backup (KTD16, ADR 0084): a repeatable-read, read-only transaction exports
 * its snapshot (`pg_export_snapshot()`), `pg_dump --snapshot` dumps exactly that state, and the audit chain heads, the
 * file references and the migration set are read in the same transaction. Writes that commit meanwhile are in none of
 * them, so `audit-heads.json` matches the heads inside the dump. The transaction stays open until the dump finished
 * (the exported snapshot lives only as long as it does).
 */
final class DatabaseSnapshot
{
    /** Files the database references on the local disk (`private/` of the storage volume), as the 1.0 restore drill. */
    private const string FILE_REFERENCES = <<<'SQL'
        SELECT 'private/' || path AS file FROM attachments WHERE disk = 'local'
        UNION SELECT 'private/' || path FROM renditions WHERE disk = 'local'
        UNION SELECT 'private/' || path FROM document_links WHERE disk = 'local'
        SQL;

    private ?string $id = null;

    public function __construct(private readonly ConnectionInterface $db) {}

    /** Begins the transaction and exports its snapshot; returns the snapshot ID for `pg_dump --snapshot`. */
    public function open(): string
    {
        $this->db->beginTransaction();
        $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $id = $this->db->scalar('SELECT pg_export_snapshot()');
        if (! is_string($id) || preg_match('/^[0-9A-F]+-[0-9A-F]+-\d+$/D', $id) !== 1) {
            $this->close();
            throw new RuntimeException('Postgres exported no usable snapshot.');
        }

        return $this->id = $id;
    }

    /** @return array{epoch: int, heads: array<int, array{seq: int, hash: string}>} */
    public function heads(): array
    {
        $this->assertOpen();

        return ['epoch' => ChainEpoch::current($this->db), 'heads' => ChainHeads::read($this->db)];
    }

    /**
     * Streams the referenced files' paths (relative to the storage volume), so a large instance never holds the
     * list twice.
     *
     * @param  Closure(string): void  $each
     */
    public function fileReferences(Closure $each): int
    {
        $this->assertOpen();
        $count = 0;
        foreach ($this->db->cursor(self::FILE_REFERENCES) as $row) {
            $each((string) $row->file);
            $count++;
        }

        return $count;
    }

    /** @return list<string> the migrations the database ran, in order (U17 decides "newer" by this set) */
    public function migrations(): array
    {
        $this->assertOpen();

        return array_values(array_map(static fn (stdClass $row): string => (string) $row->migration, $this->db->select('SELECT migration FROM migrations ORDER BY id')));
    }

    public function serverVersion(): string
    {
        $this->assertOpen();

        return (string) $this->db->scalar('SHOW server_version');
    }

    /** Ends the read-only transaction (and with it the exported snapshot). Idempotent. */
    public function close(): void
    {
        if ($this->db->transactionLevel() > 0) {
            $this->db->rollBack();
        }
        $this->id = null;
    }

    private function assertOpen(): void
    {
        if ($this->id === null) {
            throw new RuntimeException('The snapshot is not open.');
        }
    }
}
