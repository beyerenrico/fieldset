<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Exports\Writers\CsvDialect;
use App\Domain\Reports\Exports\Writers\CsvExportWriter;

/**
 * A saved report result as CSV (download and scheduled email attachment): UTF-8 with BOM, one row per
 * group plus the total, values as exact decimal strings in the locale's CSV dialect (de: `;` and decimal
 * comma), money with a currency column, labels and headers neutralized against formula injection. A
 * truncated or currency-mixed result ends with a note line.
 */
final class ReportCsv
{
    public static function make(ReportResult $result, ?string $locale = null): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            return '';
        }
        $dialect = CsvDialect::forLocale($locale ?? app()->getLocale());
        $put = static fn (array $line): int|false => fputcsv($handle, array_map(CsvExportWriter::text(...), $line), $dialect->delimiter, escape: '');
        $withMetric = $result->metric !== ReportMetric::Count;
        $withCurrency = $withMetric && $result->isMoney;

        fwrite($handle, "\u{FEFF}");
        $put(array_values(array_filter([
            $result->groupLabel, (string) __('reports.result.submissions'),
            $withMetric ? $result->metric->label().': '.$result->metricLabel : null, $withCurrency ? (string) __('reports.result.currency') : null,
        ], is_string(...))));
        foreach ([...$result->rows, $result->total] as $row) {
            $line = [$row->label, (string) $row->submissions];
            if ($withMetric) {
                $line[] = $dialect->decimal((string) $row->value($result->metric));
            }
            if ($withCurrency) {
                $line[] = $row->mixed ? (string) __('reports.result.mixed') : ($row->currency ?? $result->currency);
            }
            $put($line);
        }
        foreach (self::notes($result) as $note) {
            $put([$note]);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @return list<string> */
    public static function notes(ReportResult $result): array
    {
        $notes = [];
        if ($result->truncated) {
            $notes[] = (string) __('reports.result.truncated', ['count' => count($result->rows)]);
        }
        if ($result->metric !== ReportMetric::Count && $result->mixedCurrencies() !== []) {
            $notes[] = (string) __('reports.result.mixed_currencies', ['currencies' => implode(', ', $result->mixedCurrencies())]);
        }

        return $notes;
    }
}
