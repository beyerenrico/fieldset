<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use App\Domain\Dashboards\DashboardCache;
use App\Domain\Dashboards\DashboardContext;
use App\Domain\Dashboards\DashboardData;
use App\Domain\Dashboards\DashboardQueries;
use App\Domain\Dashboards\DateRange;
use App\Domain\Dashboards\SubmissionsChart;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Measures the QueryCatalog on real data (`app:dashboard-benchmark`): per query the median server-side
 * execution time of EXPLAIN ANALYZE over several runs, the scans used and any full scans of large tables;
 * per widget the median PHP time of an uncached DashboardData call. Needs the tenant set.
 */
final readonly class DashboardBenchmark
{
    public function __construct(private QueryCatalog $catalog, private DashboardQueries $queries, private SubmissionsChart $chart) {}

    /**
     * @param  array{number: string, date: string, choice: string, choice_value: string}  $keys
     * @return list<array{name: string, ms: float, rows: int, scans: string, full_scans: string}>
     */
    public function queries(User $manager, User $reviewer, Form $form, array $keys, string $since, int $runs): array
    {
        $range = DateRange::lastDays(7, 'Europe/Berlin');
        $all = [
            ...$this->catalog->widgets($manager, $reviewer, $range),
            ...$this->catalog->valueLookups($form->organization_id, $form->id, $keys, $since),
            ...$this->catalog->reports($manager, $reviewer, $form, $keys, $since),
        ];
        $results = [];
        foreach ($all as $name => $query) {
            QueryPlan::explain($query, analyze: true); // warm-up
            $plan = QueryPlan::explain($query, analyze: true);
            $times = [(float) $plan->executionMs];
            for ($i = 1; $i < $runs; $i++) {
                $times[] = (float) QueryPlan::explain($query, analyze: true)->executionMs;
            }
            $results[] = [
                'name' => $name, 'ms' => $this->median($times), 'rows' => (int) ($plan->plan['Actual Rows'] ?? 0),
                'scans' => implode('; ', array_unique($plan->scans())), 'full_scans' => implode('; ', $plan->fullScans()),
            ];
        }

        return $results;
    }

    /** @return array<string, float> widget => median ms of an uncached DashboardData call */
    public function widgets(User $manager, User $reviewer, int $organizationId, int $runs): array
    {
        $range = DateRange::lastDays(7, 'Europe/Berlin');
        $manage = new DashboardContext($manager, $organizationId, $range);
        $review = new DashboardContext($reviewer, $organizationId, $range);
        $calls = [
            'KPIs (4 counts)' => fn (DashboardData $d): mixed => $d->kpis($manage),
            'Submissions over time per form' => fn (DashboardData $d): mixed => $d->submissionsChart($manage),
            'Workflow failures' => fn (DashboardData $d): mixed => $d->workflowFailures($manage),
            'Review queue (reviewer)' => fn (DashboardData $d): mixed => $d->reviewQueue($review),
            'Recent submissions (sees all)' => fn (DashboardData $d): mixed => $d->recentSubmissions($manage),
            'Recent submissions (reviewer)' => fn (DashboardData $d): mixed => $d->recentSubmissions($review),
            'Draft forms' => fn (DashboardData $d): mixed => $d->draftForms($manage),
            'Recently published' => fn (DashboardData $d): mixed => $d->recentlyPublished($manage),
        ];
        $results = [];
        foreach ($calls as $name => $call) {
            $times = [];
            for ($i = 0; $i <= $runs; $i++) {
                $data = new DashboardData($this->queries, new DashboardCache(new Repository(new ArrayStore)), $this->chart);
                $started = hrtime(true);
                $call($data);
                if ($i > 0) { // the first call warms up
                    $times[] = (hrtime(true) - $started) / 1e6;
                }
            }
            $results[$name] = $this->median($times);
        }

        return $results;
    }

    /** submitted_at of the n-th newest submission of the form: "since" bound of an n-row slice. */
    public static function sliceSince(Form $form, int $rows): string
    {
        $at = DB::table('submissions')->where('organization_id', $form->organization_id)->where('form_id', $form->id)
            ->whereNotNull('submitted_at')->orderByDesc('submitted_at')->offset(max(0, $rows - 1))->value('submitted_at');

        return is_string($at) ? $at : now()->subYear()->toDateTimeString();
    }

    /**
     * Lookup keys from a definition: first numeric, date and choice (select/radio) element at top level.
     *
     * @return array{number: string, date: string, choice: string, choice_value: string}
     */
    public static function keysFor(stdClass $definition): array
    {
        $keys = ['number' => '', 'date' => '', 'choice' => '', 'choice_value' => ''];
        $visit = function (array $elements) use (&$visit, &$keys): void {
            foreach ($elements as $e) {
                if (! $e instanceof stdClass || ! is_string($e->type ?? null)) {
                    continue;
                }
                $visit(is_array($e->elements ?? null) && $e->type !== 'repeater' ? $e->elements : []);
                $key = is_string($e->key ?? null) ? $e->key : '';
                $numeric = in_array($e->type, ['number', 'money', 'rating'], true) || ($e->type === 'calculated' && ($e->format ?? 'number') !== 'text');
                $keys['number'] = $keys['number'] ?: ($numeric ? $key : '');
                $keys['date'] = $keys['date'] ?: ($e->type === 'date' ? $key : '');
                $option = in_array($e->type, ['select', 'radio'], true) && is_array($e->options ?? null) ? ($e->options[0]->value ?? null) : null;
                if ($keys['choice'] === '' && is_scalar($option)) {
                    [$keys['choice'], $keys['choice_value']] = [$key, (string) $option];
                }
            }
        };
        foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
            $visit($page instanceof stdClass && is_array($page->elements ?? null) ? $page->elements : []);
        }

        return $keys;
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return round(count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 2);
    }
}
