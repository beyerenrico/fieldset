<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Filters\DateRange;
use App\Domain\Reports\Filters\ReportScope;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportGrouping;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\Saved\ReportRow;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use stdClass;

/**
 * Computes a saved report (BUILD_PROMPT §8.4) in SQL over `submission_values`.
 *
 * API (also for dashboard widgets that pin saved reports):
 *
 *     app(SavedReportQuery::class)->run(SavedReport $report, ?DateRange $range = null, ?User $viewer = null): ReportResult
 *
 * - Call inside the report's tenant (TenantContext set; Filament requests already are).
 * - `$viewer` defaults to the authenticated user; it must pass SavedReportPolicy::view (else an
 *   AuthorizationException). Rows are the submissions the viewer may see (§8.6), so two viewers can get
 *   different numbers for the same shared report.
 * - `$range` narrows the report's own date filter (intersection) on submitted_at, e.g.
 *   DateRange::lastDays(7, $tz) or new DateRange($fromUtc, $untilUtc) for a dashboard date filter.
 * - Result: ReportResult with rows (ReportRow: key, label, submissions, sum, avg) and a total row;
 *   sum/avg are exact decimal strings (numeric(38,10) in Postgres, trailing zeros trimmed), never floats.
 *   `$result->chart()` returns ['labels' => [...], 'values' => [...decimal strings]] for a chart.
 * - Day/week/month groups and date filters use the organization's time zone.
 */
final readonly class SavedReportQuery
{
    public function __construct(private ReportScope $scope) {}

    public function run(SavedReport $report, ?DateRange $range = null, ?User $viewer = null): ReportResult
    {
        $viewer ??= auth()->user();
        if (! $viewer instanceof User) {
            throw new LogicException('A saved report is always computed for a viewer.');
        }
        Gate::forUser($viewer)->authorize('view', $report);

        $form = $report->form;
        $catalog = FieldCatalog::forForm($form);
        $timezone = self::timezone($report);
        $grouping = ReportGrouping::parse($report->group_by, $catalog);
        $metricField = $this->metricField($report, $catalog);
        $metric = $metricField instanceof ReportField ? $report->metric : ReportMetric::Count;

        $submissions = $this->scope->submissions($viewer, $form, $report->reportFilters(), $timezone, $range, $catalog)->reorder()
            ->select(['submissions.uuid', 'submissions.status', 'submissions.created_by', 'submissions.submitted_at', 'submissions.form_version_id'])
            ->toBase();

        $rows = $this->aggregate($submissions, $grouping, $metricField, $timezone, true);
        $labels = $grouping->labels(array_map(static fn (stdClass $r): ?string => is_string($r->grp) ? $r->grp : null, $rows));
        $total = $this->aggregate($submissions, ReportGrouping::parse(null, $catalog), $metricField, $timezone, false)[0] ?? null;

        return new ReportResult(
            $metric, $metricField?->key, $metricField instanceof ReportField ? $metricField->label : (string) __('reports.metric.count'),
            $grouping->value(), $grouping->label(),
            array_map(fn (stdClass $r): ReportRow => $this->row($r, $labels), $rows),
            $total instanceof stdClass ? $this->row($total, [], (string) __('reports.result.total')) : new ReportRow(null, (string) __('reports.result.total'), 0),
            $metricField?->isMoney() ?? false, $metricField?->currency() ?? '',
        );
    }

    public static function timezone(SavedReport $report): string
    {
        $timezone = Organization::query()->whereKey($report->organization_id)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone');
    }

    /** @return list<stdClass> */
    private function aggregate(QueryBuilder $submissions, ReportGrouping $grouping, ?ReportField $metric, string $timezone, bool $grouped): array
    {
        [$expression, $bindings] = $grouping->expression($timezone);
        $query = DB::query()->fromSub($submissions, 's')
            ->when($grouping->field instanceof ReportField, fn (QueryBuilder $q) => $q->leftJoin('submission_values as g', fn (JoinClause $j) => $j
                ->on('g.submission_uuid', '=', 's.uuid')->where('g.key', '=', $grouping->field?->key)->whereRaw("g.path = '/' || g.key")))
            ->when($metric instanceof ReportField, fn (QueryBuilder $q) => $q->leftJoin('submission_values as m', fn (JoinClause $j) => $j
                ->on('m.submission_uuid', '=', 's.uuid')->where('m.key', '=', $metric?->key)))
            ->selectRaw("{$expression} as grp", $bindings)
            ->selectRaw('count(distinct s.uuid) as submissions');

        $query = $metric instanceof ReportField
            ? $query->selectRaw('trim_scale(sum(m.value_num))::text as total, trim_scale(round(avg(m.value_num), 10))::text as average, count(m.value_num) as value_count')
            : $query->selectRaw('null::text as total, null::text as average, 0 as value_count');

        if ($grouped) {
            $query->groupByRaw('1')->orderByRaw($grouping->isTime() ? '1 asc nulls last' : 'count(distinct s.uuid) desc, 1 asc nulls last');
        }

        return array_values($query->get()->all());
    }

    /** @param  array<string, string>  $labels */
    private function row(stdClass $row, array $labels, ?string $label = null): ReportRow
    {
        $key = is_string($row->grp ?? null) ? $row->grp : null;

        return new ReportRow(
            $key,
            $label ?? ($key === null ? (string) __('reports.result.none') : ($labels[$key] ?? $key)),
            (int) $row->submissions,
            is_string($row->total) ? $row->total : null,
            is_string($row->average) ? $row->average : null,
            (int) $row->value_count,
        );
    }

    private function metricField(SavedReport $report, FieldCatalog $catalog): ?ReportField
    {
        if (! $report->metric->needsField() || $report->metric_key === null) {
            return null;
        }
        $field = $catalog->get($report->metric_key);

        return $field instanceof ReportField && $field->isNumeric() ? $field : null;
    }
}
