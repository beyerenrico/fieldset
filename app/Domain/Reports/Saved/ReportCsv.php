<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Exports\Writers\CsvExportWriter;

/**
 * A saved report result as CSV (download and scheduled email attachment): UTF-8 with BOM, one row per
 * group plus the total, values as exact decimal strings, labels neutralized against formula injection.
 */
final class ReportCsv
{
    public static function make(ReportResult $result): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            return '';
        }
        $withMetric = $result->metric !== ReportMetric::Count;
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, array_values(array_filter([$result->groupLabel, (string) __('reports.result.submissions'), $withMetric ? $result->metric->label().': '.$result->metricLabel : null], is_string(...))), escape: '');
        foreach ([...$result->rows, $result->total] as $row) {
            $line = [CsvExportWriter::text($row->label), (string) $row->submissions];
            if ($withMetric) {
                $line[] = (string) $row->value($result->metric);
            }
            fputcsv($handle, $line, escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
