<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * A Postgres plan (`EXPLAIN (FORMAT JSON)`, optionally with ANALYZE) of a query builder, with the checks
 * the performance tests and the benchmark need: which large tables are read without an index condition.
 */
final readonly class QueryPlan
{
    /** Tables that grow with the number of submissions. */
    public const array LARGE_TABLES = ['submissions', 'submission_values', 'tasks', 'renditions', 'workflow_runs', 'mail_messages'];

    /** @param  array<string, mixed>  $plan */
    private function __construct(public array $plan, public ?float $executionMs, public ?float $planningMs) {}

    /** @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query */
    public static function explain(EloquentBuilder|QueryBuilder $query, bool $analyze = false): self
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $options = $analyze ? 'ANALYZE, BUFFERS, FORMAT JSON' : 'FORMAT JSON';
        $row = (array) DB::selectOne("EXPLAIN ({$options}) ".$base->toSql(), $base->getBindings());

        try {
            $decoded = json_decode((string) ($row['QUERY PLAN'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Unreadable EXPLAIN output.', $e->getCode(), previous: $e);
        }
        $root = is_array($decoded) && is_array($decoded[0] ?? null) ? $decoded[0] : [];
        $plan = is_array($root['Plan'] ?? null) ? $root['Plan'] : [];

        return new self(
            $plan,
            isset($root['Execution Time']) ? (float) $root['Execution Time'] : null,
            isset($root['Planning Time']) ? (float) $root['Planning Time'] : null,
        );
    }

    /** @return list<array<string, mixed>> every plan node, depth first */
    public function nodes(): array
    {
        $nodes = [];
        $walk = function (array $node) use (&$walk, &$nodes): void {
            $nodes[] = $node;
            foreach (is_array($node['Plans'] ?? null) ? $node['Plans'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($this->plan);

        return $nodes;
    }

    /**
     * Reads of the given tables that are not narrowed by an index: sequential scans and index scans
     * without an index condition (a full index walk, e.g. only for ordering).
     *
     * @param  list<string>  $tables
     * @return list<string> e.g. "Seq Scan on submissions"
     */
    public function fullScans(array $tables = self::LARGE_TABLES): array
    {
        $found = [];
        foreach ($this->nodes() as $node) {
            $relation = $node['Relation Name'] ?? null;
            if (! is_string($relation) || ! in_array($relation, $tables, true)) {
                continue;
            }
            $type = (string) ($node['Node Type'] ?? '');
            $unindexed = $type === 'Seq Scan' || (in_array($type, ['Index Scan', 'Index Only Scan'], true) && ! isset($node['Index Cond']));
            if ($unindexed) {
                $found[] = "{$type} on {$relation}".(isset($node['Index Name']) ? " using {$node['Index Name']}" : '');
            }
        }

        return $found;
    }

    /** @return list<string> scan nodes with table and index, e.g. "Index Scan using submissions_org_submitted_at_idx on submissions" */
    public function scans(): array
    {
        $scans = [];
        foreach ($this->nodes() as $node) {
            $type = (string) ($node['Node Type'] ?? '');
            if (! str_contains($type, 'Scan')) {
                continue;
            }
            $index = isset($node['Index Name']) ? ' using '.$node['Index Name'] : '';
            $relation = isset($node['Relation Name']) ? ' on '.$node['Relation Name'] : '';
            $scans[] = $type.$index.$relation;
        }

        return $scans;
    }
}
