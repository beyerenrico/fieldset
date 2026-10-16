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
use App\Domain\Reports\Saved\ReportPlan;
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
 *     app(SavedReportQuery::class)->run(SavedReport $report, ?DateRange $range = null, ?User $viewer = null, bool $periodFromRange = false): ReportResult
 *
 * - Call inside the report's tenant (TenantContext set; Filament requests already are).
 * - `$viewer` defaults to the authenticated user; it must pass SavedReportPolicy::view (else an
 *   AuthorizationException). Rows are the submissions the viewer may see (§8.6), so two viewers can get
 *   different numbers for the same shared report.
 * - `$range` narrows the report's own date filter (intersection) on submitted_at, e.g.
 *   DateRange::lastDays(7, $tz) or new DateRange($fromUtc, $untilUtc) for a dashboard date filter.
 *   `$periodFromRange` (scheduled mails) replaces the report's relative period with `$range` instead.
 * - Result: ReportResult with rows (ReportRow: key, label, submissions, sum, avg, currency) and a total
 *   row; sum/avg are exact decimal strings (numeric(38,10) in Postgres, trailing zeros trimmed), never
 *   floats. Money is summed per currency of the submission's form version; a group mixing currencies
 *   has no sum/avg (`mixed`). At most `reports.saved.max_groups` groups (`truncated`; time groups keep
 *   the newest). `$result->chart()` returns ['labels' => [...], 'values' => [...decimal strings]].
 * - Day/week/month groups and date filters use the organization's time zone; labels the app locale.
 */
final readonly class SavedReportQuery
{
    public function __construct(private ReportScope $scope) {}

    public function run(SavedReport $report, ?DateRange $range = null, ?User $viewer = null, bool $periodFromRange = false): ReportResult
    {
        $viewer ??= auth()->user();
        if (! $viewer instanceof User) {
            throw new LogicException('A saved report is always computed for a viewer.');
        }
        Gate::forUser($viewer)->authorize('view', $report);

        $plan = $this->plan($report, $viewer, $range, $periodFromRange);
        $max = self::maxGroups();
        $rows = array_values($this->aggregate($plan, true, $max + 1)->get()->all());
        $truncated = count($rows) > $max;
        $rows = $this->ordered($plan->grouping, array_slice($rows, 0, $max));
        $labels = $plan->grouping->labels(array_map(static fn (stdClass $r): ?string => is_string($r->grp) ? $r->grp : null, $rows));
        $total = $this->aggregate($plan, false)->first();
        $field = $plan->metricField;

        return new ReportResult(
            $plan->metric, $field?->key, $field instanceof ReportField ? $field->label : (string) __('reports.metric.count'),
            $plan->grouping->value(), $plan->grouping->label(),
            array_map(fn (stdClass $r): ReportRow => $this->row($r, $plan, $labels), $rows),
            $total instanceof stdClass ? $this->row($total, $plan, [], (string) __('reports.result.total')) : new ReportRow(null, (string) __('reports.result.total'), 0),
            $plan->isMoney(), $field?->currency() ?? '', $truncated,
            $total instanceof stdClass && (int) $total->value_count > 0 ? $plan->currencies($this->versions($total)) : [],
            $plan->scales(),
        );
    }

    /**
     * The aggregate queries exactly as run() executes them (no authorization: callers authorize first;
     * the EXPLAIN tests and the benchmark plan them).
     *
     * @return array{rows: QueryBuilder, total: QueryBuilder}
     */
    public function queries(SavedReport $report, User $viewer, ?DateRange $range = null, bool $periodFromRange = false): array
    {
        $plan = $this->plan($report, $viewer, $range, $periodFromRange);

        return ['rows' => $this->aggregate($plan, true, self::maxGroups() + 1), 'total' => $this->aggregate($plan, false)];
    }

    public static function timezone(SavedReport $report): string
    {
        $timezone = Organization::query()->whereKey($report->organization_id)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone');
    }

    public static function maxGroups(): int
    {
        return max(1, (int) config('reports.saved.max_groups', 500));
    }

    private function plan(SavedReport $report, User $viewer, ?DateRange $range, bool $periodFromRange): ReportPlan
    {
        $form = $report->form;
        $catalog = FieldCatalog::forForm($form);
        $timezone = self::timezone($report);
        $metricField = $this->metricField($report, $catalog);
        $filters = $periodFromRange ? $report->reportFilters()->withoutPeriod() : $report->reportFilters();
        $currencies = $metricField?->currencies() ?? [];

        $submissions = $this->scope->submissions($viewer, $form, $filters, $timezone, $range, $catalog)->reorder()
            ->select(['submissions.uuid', 'submissions.status', 'submissions.created_by', 'submissions.submitted_at', 'submissions.form_version_id'])
            ->toBase();

        return new ReportPlan(
            $catalog, ReportGrouping::parse($report->group_by, $catalog),
            $metricField instanceof ReportField ? $report->metric : ReportMetric::Count, $metricField, $timezone, $submissions,
            count(array_unique($currencies)) > 1 ? $currencies : [],
        );
    }

    private function aggregate(ReportPlan $plan, bool $grouped, ?int $limit = null): QueryBuilder
    {
        $grouping = $grouped ? $plan->grouping : ReportGrouping::parse(null, $plan->catalog);
        $metric = $plan->metricField;
        [$expression, $bindings] = $grouping->expression($plan->timezone);
        $query = DB::query()->fromSub($plan->submissions, 's')
            ->when($grouping->field instanceof ReportField, fn (QueryBuilder $q) => $q->leftJoin('submission_values as g', fn (JoinClause $j) => $j
                ->on('g.submission_uuid', '=', 's.uuid')->where('g.key', '=', $grouping->field?->key)->whereRaw("g.path = '/' || g.key")))
            ->when($metric instanceof ReportField, fn (QueryBuilder $q) => $q->leftJoin('submission_values as m', fn (JoinClause $j) => $j
                ->on('m.submission_uuid', '=', 's.uuid')->where('m.key', '=', $metric?->key)))
            ->selectRaw("{$expression} as grp", $bindings)
            ->selectRaw('count(distinct s.uuid) as submissions');

        $query = $metric instanceof ReportField
            ? $query->selectRaw('trim_scale(sum(m.value_num))::text as total, trim_scale(round(avg(m.value_num), 10))::text as average, count(m.value_num) as value_count')
            : $query->selectRaw('null::text as total, null::text as average, 0 as value_count');
        if ($plan->currencyByVersion !== []) {
            // Which form versions (hence currencies) the values of a group come from.
            $query->selectRaw("string_agg(distinct s.form_version_id::text, ',') filter (where m.value_num is not null) as versions");
        }

        if ($grouped) {
            // Time groups: the newest N (re-ordered oldest first by ordered()); others: the largest N.
            $query->groupByRaw('1')->orderByRaw($grouping->isTime() ? '1 desc nulls last' : 'count(distinct s.uuid) desc, 1 asc nulls last')
                ->when($limit !== null, fn (QueryBuilder $q) => $q->limit((int) $limit));
        }

        return $query;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<stdClass> time groups chronologically with "no value" last; others as queried
     */
    private function ordered(ReportGrouping $grouping, array $rows): array
    {
        if (! $grouping->isTime()) {
            return $rows;
        }
        $rows = array_reverse($rows);

        return [...array_filter($rows, static fn (stdClass $r): bool => $r->grp !== null), ...array_filter($rows, static fn (stdClass $r): bool => $r->grp === null)];
    }

    /** @param  array<string, string>  $labels */
    private function row(stdClass $row, ReportPlan $plan, array $labels, ?string $label = null): ReportRow
    {
        $key = is_string($row->grp ?? null) ? $row->grp : null;
        [$currency, $mixed] = (int) $row->value_count > 0 ? $plan->currencyOf($this->versions($row)) : [null, false];

        return new ReportRow(
            $key,
            $label ?? ($key === null ? (string) __('reports.result.none') : ($labels[$key] ?? $key)),
            (int) $row->submissions,
            ! $mixed && is_string($row->total) ? $row->total : null,
            ! $mixed && is_string($row->average) ? $row->average : null,
            (int) $row->value_count,
            $currency,
            $mixed,
        );
    }

    private function versions(stdClass $row): ?string
    {
        return is_string($row->versions ?? null) ? $row->versions : null;
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
