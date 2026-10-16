<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use stdClass;

/** Human-readable, translated lines of a stored import report (`forms.import_report`). */
final class ImportReportPresenter
{
    public static function summary(?stdClass $report): string
    {
        $report ??= new stdClass;
        $stats = $report->stats ?? null;
        $stat = static fn (string $name): int => $stats instanceof stdClass && is_int($stats->{$name} ?? null) ? $stats->{$name} : 0;

        return (string) __('forms.import.report.summary', [
            'file' => is_string($report->file ?? null) ? $report->file : '',
            'imported' => $stat('imported'),
            'fields' => $stat('fields'),
            'unmapped' => $stat('unmapped'),
            'low' => $stat('low_confidence'),
        ]);
    }

    /** @return list<string> one line per unmapped field, then one per warning */
    public static function lines(?stdClass $report): array
    {
        $report ??= new stdClass;
        $lines = [];
        foreach (self::list($report->unmapped ?? null) as $item) {
            $lines[] = (string) __('forms.import.report.unmapped_line', [
                'page' => (int) ($item->page ?? 0),
                'field' => self::str($item->field ?? null),
                'reason' => self::translate('forms.import.reasons.', self::str($item->reason ?? null)),
            ]);
        }
        foreach (self::list($report->warnings ?? null) as $item) {
            $params = $item->params ?? null;
            $message = self::translate('forms.import.warnings.', self::str($item->code ?? null), $params instanceof stdClass ? array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                get_object_vars($params),
            ) : []);
            $field = self::str($item->field ?? null);
            $lines[] = $field === '' ? $message : (string) __('forms.import.report.warning_line', [
                'page' => (int) ($item->page ?? 0), 'field' => $field, 'message' => $message,
            ]);
        }

        return $lines;
    }

    /** @param  array<string, string>  $params */
    private static function translate(string $prefix, string $code, array $params = []): string
    {
        $key = $prefix.$code;
        $text = __($key, $params);

        return is_string($text) && $text !== $key ? $text : $code;
    }

    /** @return list<stdClass> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => $v instanceof stdClass)) : [];
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
