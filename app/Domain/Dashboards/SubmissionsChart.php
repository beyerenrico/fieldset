<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use App\Domain\Forms\Models\Form;

/**
 * "Submissions over time per form" (§8.1): one series per form with the most submissions in the
 * range (up to `dashboards.chart_max_series`), the rest summed as "Other forms" (label null), one point per day.
 */
final class SubmissionsChart
{
    /**
     * @param  list<\stdClass>  $rows  {form_id, day (Y-m-d), total}
     * @return array{labels: list<string>, series: list<array{label: ?string, data: list<int>}>}
     */
    public function build(DateRange $range, array $rows): array
    {
        $days = $range->days();
        $perForm = [];
        foreach ($rows as $row) {
            $formId = (int) $row->form_id;
            $day = substr((string) $row->day, 0, 10);
            $perForm[$formId][$day] = ($perForm[$formId][$day] ?? 0) + (int) $row->total;
        }
        uasort($perForm, fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

        $max = max(1, (int) config('dashboards.chart_max_series', 5));
        $top = array_slice($perForm, 0, $max, true);
        $names = Form::query()->whereIn('id', array_keys($top))->pluck('name', 'id')->all();

        $series = [];
        foreach ($top as $formId => $counts) {
            $series[] = ['label' => (string) ($names[$formId] ?? '#'.$formId), 'data' => $this->points($days, $counts)];
        }
        $rest = array_slice($perForm, $max, null, true);
        if ($rest !== []) {
            $other = [];
            foreach ($rest as $counts) {
                foreach ($counts as $day => $total) {
                    $other[$day] = ($other[$day] ?? 0) + $total;
                }
            }
            $series[] = ['label' => null, 'data' => $this->points($days, $other)]; // translated when rendered (cache is per organization, not per locale)
        }

        return ['labels' => $days, 'series' => $series];
    }

    /**
     * @param  list<string>  $days
     * @param  array<string, int>  $counts
     * @return list<int>
     */
    private function points(array $days, array $counts): array
    {
        return array_map(fn (string $day): int => $counts[$day] ?? 0, $days);
    }
}
