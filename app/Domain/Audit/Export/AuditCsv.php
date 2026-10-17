<?php

declare(strict_types=1);

namespace App\Domain\Audit\Export;

use App\Domain\Audit\AuditLabels;
use App\Domain\Audit\Models\Activity;
use App\Domain\Reports\Exports\Writers\CsvDialect;
use App\Domain\Reports\Exports\Writers\CsvExportWriter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audit entries as CSV (§8.11 "CSV export"): UTF-8 with BOM, the locale's CSV dialect, cells neutralized
 * against formula injection (the M5 writer), times in the given time zone with offset, the before/after diff
 * and the properties as JSON (already redacted when the entry was written), plus the hash chain columns.
 */
final class AuditCsv
{
    private const int CHUNK = 500;

    /** @return list<string> */
    public static function headers(): array
    {
        return array_map(static fn (string $c): string => (string) __('audit.csv.'.$c), [
            'time', 'event', 'event_label', 'actor_type', 'actor_id', 'actor', 'subject_type', 'subject_id',
            'ip_address', 'user_agent', 'request_id', 'changes', 'properties', 'chain_seq', 'hash',
        ]);
    }

    /**
     * @param  resource  $handle
     * @param  Builder<Activity>  $query
     * @return int rows written
     */
    public static function write($handle, Builder $query, string $locale, string $timezone): int
    {
        $dialect = CsvDialect::forLocale($locale);
        $put = static fn (array $line): int|false => fputcsv($handle, array_map(CsvExportWriter::text(...), $line), $dialect->delimiter, escape: '');
        fwrite($handle, "\u{FEFF}");
        $put(self::headers());
        $rows = 0;
        foreach ($query->with('causer')->lazyById(self::CHUNK) as $entry) {
            /** @var Activity $entry */
            $put(self::line($entry, $timezone));
            $rows++;
        }

        return $rows;
    }

    /** @return list<string> */
    private static function line(Activity $entry, string $timezone): array
    {
        return [
            $entry->created_at?->setTimezone($timezone)->toIso8601String() ?? '',
            (string) $entry->event, AuditLabels::event($entry->event), $entry->actor_type,
            $entry->causer_id === null ? '' : (string) $entry->causer_id, AuditLabels::actor($entry),
            $entry->subject_type === null ? '' : class_basename($entry->subject_type), (string) $entry->subject_id,
            (string) $entry->ip_address, (string) $entry->user_agent, (string) $entry->request_id,
            self::json($entry->attribute_changes?->all() ?? []), self::json($entry->properties?->all() ?? []),
            $entry->chain_seq === null ? '' : (string) $entry->chain_seq, (string) $entry->hash,
        ];
    }

    /** @param  array<array-key, mixed>  $value */
    private static function json(array $value): string
    {
        return $value === [] ? '' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
