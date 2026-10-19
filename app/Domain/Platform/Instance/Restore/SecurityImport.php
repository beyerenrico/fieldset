<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Materialises a SecurityExport in the restored database (R64, plan U18, ADR 0086), so the unchanged R64 carry (and
 * the sequence floors) read it as their source: a schema `fsa_carry_<hex>` gets one table per exported table, typed like
 * the restored database's own (`CREATE TABLE … AS SELECT <shared columns> FROM public.… WITH NO DATA`: an older schema
 * simply has fewer columns, a table it lacks is skipped), the exported rows, and a `pg_sequences` table with the last
 * values. A second connection with `search_path = fsa_carry_<hex>, pg_catalog` is the carry's source (pg_catalog after
 * it, so the schema's `pg_sequences` wins). The schema is committed before the carry's transaction (that connection must
 * see it) and dropped afterwards, whatever happened.
 */
final class SecurityImport
{
    public const string CONNECTION = 'fsa_restore_carry';

    private const int CHUNK = 500;

    private ?string $schema = null;

    public function __construct(private readonly Connection $target) {}

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws RestoreRefused `carry_format` for a document of another format or a newer major version
     */
    public function source(array $document): Connection
    {
        if (($document['format'] ?? null) !== SecurityExport::FORMAT || ! is_int($document['version'] ?? null) || $document['version'] > SecurityExport::VERSION) {
            throw new RestoreRefused('carry_format', 'The security state export is not one this version reads (format '.json_encode($document['format'] ?? null).', version '.json_encode($document['version'] ?? null).').', 'Roll back to a release that knows the export of the current one.');
        }
        $this->schema = 'fsa_carry_'.bin2hex(random_bytes(4));
        $this->target->statement('CREATE SCHEMA '.$this->schema);
        foreach (is_array($document['tables'] ?? null) ? $document['tables'] : [] as $table => $data) {
            if (is_string($table) && is_array($data)) {
                $this->table($table, $data);
            }
        }
        $this->sequences(is_array($document['sequences'] ?? null) ? $document['sequences'] : []);
        $default = config('database.connections.'.$this->target->getName());
        config(['database.connections.'.self::CONNECTION => [...(is_array($default) ? $default : []), 'search_path' => $this->schema.', pg_catalog']]);
        DB::purge(self::CONNECTION);
        $source = DB::connection(self::CONNECTION);
        if (! $source instanceof Connection) {
            throw new InvalidArgumentException('No connection for the security state export.');
        }

        return $source;
    }

    /** Drops the schema and the connection (idempotent). */
    public function drop(): void
    {
        DB::purge(self::CONNECTION);
        if ($this->schema !== null) {
            $this->target->statement('DROP SCHEMA IF EXISTS '.$this->schema.' CASCADE');
            $this->schema = null;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function table(string $table, array $data): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $table) !== 1 || ! $this->target->getSchemaBuilder()->hasTable($table)) {
            return; // a table the restored (older) schema does not have, or no plain name: nothing reads it
        }
        $exported = array_values(array_filter(is_array($data['columns'] ?? null) ? $data['columns'] : [], is_string(...)));
        $shared = array_values(array_intersect($exported, $this->target->getSchemaBuilder()->getColumnListing($table)));
        if ($shared === []) {
            return;
        }
        $quoted = implode(', ', array_map(static fn (string $column): string => '"'.str_replace('"', '""', $column).'"', $shared));
        $this->target->statement("CREATE TABLE {$this->schema}.{$table} AS SELECT {$quoted} FROM public.{$table} WITH NO DATA");
        $positions = array_map(static fn (string $column): int|false => array_search($column, $exported, true), $shared);
        $rows = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = array_combine($shared, array_map(static fn (int|false $position): mixed => $position === false ? null : self::value($row[$position] ?? null), $positions));
            }
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->target->table($this->schema.'.'.$table)->insert($chunk);
        }
    }

    /** @param  array<mixed>  $sequences */
    private function sequences(array $sequences): void
    {
        $this->target->statement("CREATE TABLE {$this->schema}.pg_sequences (schemaname name, sequencename name, last_value bigint)");
        $rows = [];
        foreach ($sequences as $sequence) {
            if (is_array($sequence) && is_string($sequence['sequencename'] ?? null)) {
                $rows[] = ['schemaname' => 'public', 'sequencename' => $sequence['sequencename'], 'last_value' => is_int($sequence['last_value'] ?? null) ? $sequence['last_value'] : null];
            }
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->target->table($this->schema.'.pg_sequences')->insert($chunk);
        }
    }

    /** A JSON value as Postgres takes it from a bound parameter (booleans as text, nested values as JSON). */
    private static function value(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => $value,
        };
    }
}
