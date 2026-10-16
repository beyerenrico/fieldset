<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Dashboards\Pins\DashboardPins;
use App\Domain\Dashboards\Pins\PinnedReportData;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\Saved\ReportRow;
use App\Domain\Submissions\Admin\ValueFormatter;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use App\Filament\Admin\Widgets\Concerns\InteractsWithDashboard;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * A saved report pinned to the dashboard (§8.1 v1.0, §8.4, ADR 0034): a line for day/week/month groups,
 * bars for up to `dashboards.pinned_reports.max_chart_groups` other groups, otherwise (many groups, no
 * grouping, no rows) a compact table of the first rows and the total. Numbers are SavedReportQuery's for
 * the viewer over the dashboard date range ∩ the report's own filter, cached per user (PinnedReportData).
 *
 * Access: every `reports.view` holder may mount the class, so a widget whose report just went away
 * re-renders empty instead of failing its poll. Each instance shows data only while its report is pinned
 * by the viewer and still viewable (DashboardPins::visibleReport, which also removes a stale pin).
 */
class PinnedReportWidget extends ChartWidget
{
    use InteractsWithDashboard;

    #[Locked]
    public ?int $savedReportId = null;

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = '60s';

    private bool $resolved = false;

    private ?SavedReport $report = null;

    private ?ReportResult $result = null;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && Filament::getTenant() instanceof Organization && app(TenantContext::class)->check()
            && Gate::forUser($user)->allows('viewAny', SavedReport::class);
    }

    public function getHeading(): ?string
    {
        return $this->report()?->name;
    }

    public function getDescription(): ?string
    {
        $result = $this->result();
        if (! $result instanceof ReportResult) {
            return null;
        }
        $metric = $result->metric === ReportMetric::Count ? $result->metricLabel : $result->metric->label().' · '.$result->metricLabel;

        return $metric.' · '.$this->dashboardContext()->range->label();
    }

    protected function getType(): string
    {
        return $this->result()?->isTimeSeries() ? 'line' : 'bar';
    }

    /** @return array{datasets: list<array<string, mixed>>, labels: list<string>} */
    protected function getData(): array
    {
        $result = $this->result();
        if (! $result instanceof ReportResult || $this->showsTable($result)) {
            return ['datasets' => [], 'labels' => []];
        }
        $chart = $result->chart();

        return ['datasets' => [['label' => $result->metricLabel, 'data' => $chart['values']]], 'labels' => $chart['labels']];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        $y = ['beginAtZero' => true];
        if ($this->result()?->metric === ReportMetric::Count) {
            $y['ticks'] = ['precision' => 0];
        }

        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => $y]];
    }

    /** Text alternative for screen readers: each group with its value. */
    public function getChartAssistiveContent(): ?string
    {
        $result = $this->result();
        if (! $result instanceof ReportResult) {
            return null;
        }
        $formatter = $this->formatter();

        return implode(', ', array_map(
            fn (ReportRow $row): string => $row->label.': '.$result->display($row->value($result->metric), $formatter),
            $result->rows,
        ));
    }

    public function render(): View
    {
        $result = $this->result();
        if (! $result instanceof ReportResult) {
            return view('filament.widgets.pinned-report-gone');
        }
        if (! $this->showsTable($result)) {
            return parent::render();
        }
        $limit = max(1, (int) config('dashboards.pinned_reports.table_rows', 10));
        $rows = $result->groupBy === null ? [] : $result->rows;

        return view('filament.widgets.pinned-report-table', [
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
            'result' => $result,
            'rows' => array_slice($rows, 0, $limit),
            'more' => max(0, count($rows) - $limit),
            'url' => SavedReportResource::getUrl('view', ['record' => $this->savedReportId]),
            'formatter' => $this->formatter(),
        ]);
    }

    private function showsTable(ReportResult $result): bool
    {
        if ($result->rows === []) {
            return true;
        }

        return ! $result->isTimeSeries()
            && ($result->groupBy === null || count($result->rows) > max(1, (int) config('dashboards.pinned_reports.max_chart_groups', 12)));
    }

    private function report(): ?SavedReport
    {
        $this->resolve();

        return $this->report;
    }

    private function result(): ?ReportResult
    {
        $this->resolve();

        return $this->result;
    }

    /** Loads the report (only when pinned and viewable) and its cached result, once per request. */
    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        $user = Filament::auth()->user();
        if (! $user instanceof User || $this->savedReportId === null) {
            return;
        }
        $this->report = app(DashboardPins::class)->visibleReport($user, $this->savedReportId);
        if ($this->report instanceof SavedReport) {
            $this->result = app(PinnedReportData::class)->result($this->dashboardContext(), $this->report);
        }
    }

    private function formatter(): ValueFormatter
    {
        return new ValueFormatter(app()->getLocale(), (string) config('app.timezone'));
    }
}
