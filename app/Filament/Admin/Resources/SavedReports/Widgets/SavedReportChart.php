<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Widgets;

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\ReportResult;
use App\Domain\Reports\SavedReportQuery;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Chart of a saved report on its view page: a line over time groups, bars otherwise. Values are the
 * result's decimal strings (Chart.js parses them for drawing only; nothing is summed client-side).
 */
class SavedReportChart extends ChartWidget
{
    public ?Model $record = null;

    protected ?string $maxHeight = '320px';

    private ?ReportResult $result = null;

    public function getHeading(): string|Htmlable|null
    {
        $result = $this->result();

        if (! $result instanceof ReportResult) {
            return null;
        }

        // A count names its metric once ("Number of submissions"), sum/avg add the field.
        return $result->metric === ReportMetric::Count ? $result->metric->label() : $result->metric->label().' · '.$result->metricLabel;
    }

    protected function getType(): string
    {
        return $this->result()?->isTimeSeries() ? 'line' : 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $result = $this->result();
        if (! $result instanceof ReportResult) {
            return ['datasets' => [], 'labels' => []];
        }
        $chart = $result->chart();

        return [
            'datasets' => [['label' => $result->metricLabel, 'data' => $chart['values']]],
            'labels' => $chart['labels'],
        ];
    }

    private function result(): ?ReportResult
    {
        $user = auth()->user();
        if (! $this->record instanceof SavedReport || ! $user instanceof User) {
            return null;
        }

        return $this->result ??= app(SavedReportQuery::class)->run($this->record, null, $user);
    }
}
