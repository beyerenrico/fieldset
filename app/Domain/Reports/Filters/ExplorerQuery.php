<?php

declare(strict_types=1);

namespace App\Domain\Reports\Filters;

use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Pivots `submission_values` onto explorer rows (BUILD_PROMPT §8.4). Two correlated subqueries per row
 * (index on submission_uuid; Postgres evaluates them only for the rows of the current page):
 * - `report_values`: top-level key => value_text (path = '/key'),
 * - `report_rows`: top-level repeater key => number of rows that have at least one value.
 * Sorting by a field orders by its typed column (value_num, value_date or value_text).
 */
final class ExplorerQuery
{
    /**
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public static function withValues(Builder $query, FieldCatalog $catalog): Builder
    {
        $query->select('submissions.*')->addSelect([
            'report_values' => DB::table('submission_values as rv')
                ->selectRaw('jsonb_object_agg(rv.key, rv.value_text)')
                ->whereColumn('rv.submission_uuid', 'submissions.uuid')
                ->whereRaw("rv.path = '/' || rv.key"),
        ]);
        $casts = ['report_values' => 'array'];

        if ($catalog->repeaters() !== []) {
            $counts = DB::table('submission_values as rr')
                ->selectRaw("split_part(rr.path, '/', 2) as k, count(distinct split_part(rr.path, '/', 3)) as n")
                ->whereColumn('rr.submission_uuid', 'submissions.uuid')
                ->where('rr.path', 'like', '/%/%/%')
                ->groupByRaw('1');
            $query->addSelect(['report_rows' => DB::query()->fromSub($counts, 'r')->selectRaw('jsonb_object_agg(r.k, r.n)')]);
            $casts['report_rows'] = 'array';
        }

        return $query->withCasts($casts);
    }

    /**
     * The explorer's default order: newest first, uuid as the tie-breaker. Index-backed by
     * submissions (organization_id, form_id, submitted_at, uuid) read backwards; every non-draft has a
     * submitted_at (drafts are never explorer rows), so no NULLS LAST is needed.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public static function defaultOrder(Builder $query): Builder
    {
        return $query->orderByDesc('submissions.submitted_at')->orderByDesc('submissions.uuid');
    }

    /** Typed value of a top-level key for ORDER BY (repeaters: their row count). */
    public static function sortExpression(ReportField $field): QueryBuilder
    {
        $values = DB::table('submission_values as sv')->whereColumn('sv.submission_uuid', 'submissions.uuid');

        if ($field->isRepeater()) {
            return $values->selectRaw("count(distinct split_part(sv.path, '/', 3))")->where('sv.path', 'like', '/'.addcslashes($field->key, '%_\\').'/%');
        }

        $column = match (true) {
            $field->isNumeric() => 'sv.value_num',
            $field->isDate() => 'sv.value_date',
            default => 'sv.value_text',
        };

        return $values->select($column)->where('sv.key', $field->key)->where('sv.path', '/'.$field->key)->limit(1);
    }

    /** @return array<string, string|null> top-level key => value_text */
    public static function values(Submission $record): array
    {
        $values = $record->getAttribute('report_values');

        return is_array($values) ? $values : [];
    }

    public static function rowCount(Submission $record, string $repeaterKey): int
    {
        $rows = $record->getAttribute('report_rows');

        return is_array($rows) && is_numeric($rows[$repeaterKey] ?? null) ? (int) $rows[$repeaterKey] : 0;
    }
}
