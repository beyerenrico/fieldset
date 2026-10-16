<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** "Submissions over time per form" (§8.1 Owner): one line per form, one point per day of the range. */
class SubmissionsChartWidget extends ChartWidget
{
    use InteractsWithDashboard;

    /** Distinct hues for the form lines; "Other forms" is always gray. */
    private const array COLORS = ['#2563eb', '#d97706', '#059669', '#db2777', '#7c3aed', '#0891b2', '#65a30d', '#dc2626'];

    private const string OTHER_COLOR = '#6b7280';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = '60s';

    public function getHeading(): string
    {
        return __('dashboard.chart.heading');
    }

    public function getDescription(): string
    {
        return $this->dashboardContext()->range->label();
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array{datasets: list<array<string, mixed>>, labels: list<string>} */
    protected function getData(): array
    {
        $chart = $this->dashboardData()->submissionsChart($this->dashboardContext());
        $datasets = [];
        foreach ($chart['series'] as $i => $series) {
            $color = $series['label'] === null ? self::OTHER_COLOR : self::COLORS[$i % count(self::COLORS)];
            $datasets[] = [
                'label' => $series['label'] ?? __('dashboard.chart.other_forms'),
                'data' => $series['data'],
                'borderColor' => $color,
                'backgroundColor' => $color,
                'tension' => 0.25,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(fn (string $day): string => CarbonImmutable::parse($day)->format('d.m.'), $chart['labels']),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }

    /** Text alternative for screen readers: totals per form in the range. */
    public function getChartAssistiveContent(): string
    {
        $chart = $this->dashboardData()->submissionsChart($this->dashboardContext());
        if ($chart['series'] === []) {
            return __('dashboard.chart.empty');
        }

        return implode(', ', array_map(
            fn (array $series): string => ($series['label'] ?? __('dashboard.chart.other_forms')).': '.array_sum($series['data']),
            $chart['series'],
        ));
    }
}
