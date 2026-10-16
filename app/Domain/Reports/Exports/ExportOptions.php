<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Reports\Filters\ReportFilters;

/**
 * What an export job needs (stored in exports.options): the filters, an optional selection of submission
 * uuids (bulk action), and the requesting user's locale and time zone for labels and dates.
 */
final readonly class ExportOptions
{
    /** @param  list<string>|null  $uuids */
    public function __construct(
        public ReportFilters $filters,
        public ?array $uuids,
        public string $locale,
        public string $timezone,
    ) {}

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $uuids = is_array($data['uuids'] ?? null) ? self::uuids($data['uuids']) : null;

        return new self(
            ReportFilters::fromArray(is_array($data['filters'] ?? null) ? $data['filters'] : []),
            $uuids,
            is_string($data['locale'] ?? null) ? $data['locale'] : (string) config('app.locale'),
            is_string($data['timezone'] ?? null) && in_array($data['timezone'], timezone_identifiers_list(), true) ? $data['timezone'] : 'UTC',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['filters' => $this->filters->toArray(), 'uuids' => $this->uuids, 'locale' => $this->locale, 'timezone' => $this->timezone];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    public static function uuids(array $values): array
    {
        $uuids = array_filter($values, static fn (mixed $v): bool => is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1);

        return array_slice(array_values(array_unique(array_map(strtolower(...), $uuids))), 0, max(1, (int) config('reports.exports.max_selected', 10000)));
    }
}
