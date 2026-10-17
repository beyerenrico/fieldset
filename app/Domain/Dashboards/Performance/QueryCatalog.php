<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use App\Domain\Dashboards\DashboardData;
use App\Domain\Dashboards\DashboardQueries;
use App\Domain\Dashboards\DateRange;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Filters\DateRange as ReportRange;
use App\Domain\Reports\Filters\ExplorerQuery;
use App\Domain\Reports\Filters\FieldFilter;
use App\Domain\Reports\Filters\ReportScope;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\SavedReportQuery;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Submissions\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The queries that must stay index-backed at 100k submissions per organization (§8.1 AC): every widget
 * query (exactly as the widgets run them, from DashboardQueries), the canonical `submission_values`
 * lookups (§8.4: filter by organization + form + key + value, newest first), and the REAL explorer page
 * and saved report aggregate queries (ExplorerQuery/ReportScope and SavedReportQuery::queries(), review
 * M2). Used by the EXPLAIN tests and by `app:dashboard-benchmark`. Needs the tenant set.
 */
final readonly class QueryCatalog
{
    public function __construct(private DashboardQueries $queries, private ReportScope $scope, private SavedReportQuery $reports) {}

    /** @return array<string, EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>> */
    public function widgets(User $manager, User $reviewer, DateRange $range): array
    {
        $q = $this->queries;

        return [
            'kpi: submissions in range' => $q->reportableInRange($manager, $range)->selectRaw('count(*)'),
            'kpi: pending reviews' => $q->pendingReviews()->selectRaw('count(*)'),
            'kpi: stale drafts' => $q->staleDrafts(DashboardData::staleDraftDays())->selectRaw('count(*)'),
            'kpi: documents sent' => $q->documentsSent($range)->selectRaw('count(*)'),
            'chart: submissions per form and day' => $q->submissionsPerFormPerDay($manager, $range),
            'table: workflow failures' => $q->workflowFailures($range, 10),
            'table: review queue (reviewer)' => $q->reviewQueue($reviewer, 10),
            'table: recent submissions (sees all)' => $q->recentSubmissions($manager, $range, 10),
            'table: recent submissions (reviewer visibility)' => $q->recentSubmissions($reviewer, $range, 10),
            'table: draft forms' => $q->draftForms(10),
            'table: recently published' => $q->recentlyPublished($range, 10),
        ];
    }

    /**
     * @param  array{number: string, date: string, choice: string, choice_value: string}  $keys  value keys of the form
     * @return array<string, QueryBuilder>
     */
    public function valueLookups(int $organizationId, int $formId, array $keys, string $since): array
    {
        $values = fn (string $key): QueryBuilder => DB::table('submission_values')
            ->where('organization_id', $organizationId)->where('form_id', $formId)->where('key', $key);
        $numberRange = fn (): QueryBuilder => $values($keys['number'])->select('submission_uuid')->whereBetween('value_num', ['100', '250']);

        return [
            'values: number range, newest first' => $numberRange()->addSelect('submitted_at')->orderByDesc('submitted_at')->limit(50),
            'values: date range, newest first' => $values($keys['date'])->select('submission_uuid', 'submitted_at')
                ->whereBetween('value_date', [substr($since, 0, 10), now()->toDateString()])->orderByDesc('submitted_at')->limit(50),
            'values: choice equals, newest first' => $values($keys['choice'])->select('submission_uuid', 'submitted_at')
                ->whereIn('value_text', [$keys['choice_value']])->orderByDesc('submitted_at')->limit(50),
        ];
    }

    /**
     * The explorer page (default order, value pivots) for a manager and a reviewer, filtered by a number
     * range and a choice, and the grouped sum and total of a saved report since a date — built by the
     * same code the explorer and SavedReportQuery run.
     *
     * @param  array{number: string, date: string, choice: string, choice_value: string}  $keys
     * @return array<string, EloquentBuilder<Submission>|QueryBuilder>
     */
    public function reports(User $manager, User $reviewer, Form $form, array $keys, string $since): array
    {
        $catalog = FieldCatalog::forForm($form);
        $page = fn (User $user): EloquentBuilder => ExplorerQuery::defaultOrder(ExplorerQuery::withValues($this->scope->base($user, $form), $catalog))->limit(25);
        $filtered = function (FieldFilter $filter) use ($page, $manager, $form, $catalog): EloquentBuilder {
            $query = $page($manager);
            $this->scope->field($query, $form, $filter, $catalog->get($filter->key));

            return $query;
        };
        $report = new SavedReport(['form_id' => $form->id, 'metric' => 'sum', 'metric_key' => $keys['number'], 'group_by' => 'field:'.$keys['choice'], 'filters' => []]);
        $report->organization_id = $form->organization_id;
        $report->setRelation('form', $form);
        $aggregates = $this->reports->queries($report, $manager, new ReportRange(CarbonImmutable::parse($since, 'UTC'), CarbonImmutable::now()->addDay()));

        return [
            'explorer: page of a form, newest first' => $page($manager),
            'explorer: page, reviewer visibility' => $page($reviewer),
            'explorer: page filtered by a number range' => $filtered(new FieldFilter($keys['number'], FieldFilter::BETWEEN, ['100', '250'])),
            'explorer: page filtered by a choice' => $filtered(new FieldFilter($keys['choice'], FieldFilter::IN, [$keys['choice_value']])),
            'report: sum of a number grouped by a choice since a date' => $aggregates['rows'],
        ];
    }
}
