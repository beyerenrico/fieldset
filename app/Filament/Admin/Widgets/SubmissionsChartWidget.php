<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use App\Support\Dates\LocalDate;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\HtmlString;

/**
 * "Submissions over time per form" (§8.1 Owner): one line per form, one point per day of the range,
 * counted like reports (visible to the viewer, no drafts, no void). Series differ by colour AND point
 * shape / dash pattern (not by colour alone); a visually hidden table gives the numbers to screen readers,
 * and an empty range shows the empty state instead of a blank chart (review U2). Day labels follow the locale.
 */
class SubmissionsChartWidget extends ChartWidget
{
    use InteractsWithDashboard;

    /** Distinct hues for the form lines; "Other forms" is always gray. */
    private const array COLORS = ['#2563eb', '#d97706', '#059669', '#db2777', '#7c3aed', '#0891b2', '#65a30d', '#dc2626'];

    /** Chart.js point styles and dash patterns, so series stay distinguishable without colour. */
    private const array POINT_STYLES = ['circle', 'rect', 'triangle', 'rectRot', 'star', 'crossRot', 'rectRounded', 'cross'];

    private const array DASHES = [[], [6, 4], [2, 3], [10, 3, 2, 3]];

    private const string OTHER_COLOR = '#6b7280';

    /** Up to this many days the hidden table lists every day; longer ranges list totals only. */
    private const int TABLE_DAYS = 31;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = '60s';

    public function getHeading(): string
    {
        return __('dashboard.chart.heading');
    }

    public function getDescription(): string
    {
        return $this->rangeAndFreshness('submissions_chart');
    }

    public function isEmpty(): bool
    {
        return $this->chart()['series'] === [];
    }

    public function getEmptyStateHeading(): string
    {
        return __('dashboard.chart.empty');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array{datasets: list<array<string, mixed>>, labels: list<string>} */
    protected function getData(): array
    {
        $chart = $this->chart();
        $datasets = [];
        foreach ($chart['series'] as $i => $series) {
            $color = $series['label'] === null ? self::OTHER_COLOR : self::COLORS[$i % count(self::COLORS)];
            $datasets[] = [
                'label' => $series['label'] ?? __('dashboard.chart.other_forms'),
                'data' => $series['data'],
                'borderColor' => $color,
                'backgroundColor' => $color,
                'pointStyle' => self::POINT_STYLES[$i % count(self::POINT_STYLES)],
                'pointRadius' => 4,
                'borderDash' => self::DASHES[$i % count(self::DASHES)],
                'tension' => 0.25,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(fn (string $day): string => LocalDate::dayMonth(CarbonImmutable::parse($day)), $chart['labels']),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['labels' => ['usePointStyle' => true]]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    /** Text alternative for screen readers: a table of the submissions per form (per day for short ranges). */
    public function getChartAssistiveContent(): HtmlString
    {
        $chart = $this->chart();
        if ($chart['series'] === []) {
            return new HtmlString('<p>'.e(__('dashboard.chart.empty')).'</p>');
        }
        $days = count($chart['labels']) <= self::TABLE_DAYS ? $chart['labels'] : [];
        $head = '<th scope="col">'.e(__('dashboard.columns.form')).'</th>'
            .implode('', array_map(fn (string $d): string => '<th scope="col">'.e(LocalDate::date(CarbonImmutable::parse($d))).'</th>', $days))
            .'<th scope="col">'.e(__('dashboard.chart.total')).'</th>';
        $rows = '';
        foreach ($chart['series'] as $series) {
            $cells = $days === [] ? '' : implode('', array_map(static fn (int $n): string => '<td>'.$n.'</td>', $series['data']));
            $rows .= '<tr><th scope="row">'.e($series['label'] ?? __('dashboard.chart.other_forms')).'</th>'.$cells.'<td>'.array_sum($series['data']).'</td></tr>';
        }

        return new HtmlString('<table><caption>'.e(__('dashboard.chart.heading').' · '.$this->dashboardContext()->range->label()).'</caption>'
            .'<thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>');
    }

    /** @return array{labels: list<string>, series: list<array{label: ?string, data: list<int>}>} */
    private function chart(): array
    {
        return $this->dashboardData()->submissionsChart($this->dashboardContext());
    }
}
