<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use App\Domain\Dashboards\DashboardData;
use App\Domain\Dashboards\DashboardQueries;
use App\Domain\Dashboards\DateRange;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The queries that must stay index-backed at 100k submissions per organization (§8.1 AC): every widget
 * query (exactly as the widgets run them, from DashboardQueries) and the canonical `submission_values`
 * lookups of the explorer and saved reports (§8.4: filter by organization + form + key + value, newest
 * first; aggregates). Used by the EXPLAIN tests and by `app:dashboard-benchmark`. Needs the tenant set.
 */
final readonly class QueryCatalog
{
    public function __construct(private DashboardQueries $queries) {}

    /** @return array<string, EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>> */
    public function widgets(User $manager, User $reviewer, DateRange $range): array
    {
        $q = $this->queries;

        return [
            'kpi: submissions in range' => $q->submittedInRange($range)->selectRaw('count(*)'),
            'kpi: pending reviews' => $q->pendingReviews()->selectRaw('count(*)'),
            'kpi: stale drafts' => $q->staleDrafts(DashboardData::staleDraftDays())->selectRaw('count(*)'),
            'kpi: documents sent' => $q->documentsSent($range)->selectRaw('count(*)'),
            'chart: submissions per form and day' => $q->submissionsPerFormPerDay($range),
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
     * @return array<string, EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder>
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
            'explorer: page of a form, newest first' => $this->formPage($formId),
            'explorer: page filtered by a number range' => $this->formPage($formId)->whereIn('uuid', $numberRange()),
            'explorer: page filtered by a choice' => $this->formPage($formId)
                ->whereIn('uuid', $values($keys['choice'])->select('submission_uuid')->whereIn('value_text', [$keys['choice_value']])),
            'report: count/sum/avg of a number since a date' => $values($keys['number'])
                ->selectRaw('count(*) as n, sum(value_num) as total, avg(value_num) as average')->where('submitted_at', '>=', $since),
            'report: sum of a number grouped by a choice since a date' => $this->groupedSum($organizationId, $formId, $keys, $since),
        ];
    }

    /** @return EloquentBuilder<Submission> */
    private function formPage(int $formId): EloquentBuilder
    {
        return Submission::query()->where('form_id', $formId)->where('status', '!=', SubmissionStatus::Draft->value)
            ->orderByDesc('submitted_at')->limit(25);
    }

    /** @param  array{number: string, date: string, choice: string, choice_value: string}  $keys */
    private function groupedSum(int $organizationId, int $formId, array $keys, string $since): QueryBuilder
    {
        return DB::table('submission_values as g')
            ->join('submission_values as m', function ($join) use ($keys): void {
                $join->on('m.submission_uuid', '=', 'g.submission_uuid')->on('m.organization_id', '=', 'g.organization_id')
                    ->on('m.form_id', '=', 'g.form_id')->where('m.key', '=', $keys['number']);
            })
            ->where('g.organization_id', $organizationId)->where('g.form_id', $formId)->where('g.key', $keys['choice'])
            ->where('g.submitted_at', '>=', $since)
            ->groupBy('g.value_text')
            ->select('g.value_text')->selectRaw('count(*) as n, sum(m.value_num) as total');
    }
}
