<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Submissions\Enums\SubmissionStatus;

/**
 * The group-by of a saved report: none, a submission column (status, submitter, version, day, week,
 * month of submitted_at in the report's time zone) or a groupable top-level key (`field:<key>`; dates
 * are grouped by month). Produces the SQL expression over the aliases `s` (submissions) and `g`
 * (submission_values of the key) and turns group keys into labels.
 */
final readonly class ReportGrouping
{
    public const array OPTIONS = ['status', 'submitter', 'version', 'day', 'week', 'month'];

    private function __construct(public ?string $column, public ?ReportField $field) {}

    public static function parse(?string $groupBy, FieldCatalog $catalog): self
    {
        if ($groupBy !== null && in_array($groupBy, self::OPTIONS, true)) {
            return new self($groupBy, null);
        }
        $field = $groupBy !== null && str_starts_with($groupBy, 'field:') ? $catalog->get(substr($groupBy, 6)) : null;

        return $field instanceof ReportField && $field->isGroupable() ? new self(null, $field) : new self(null, null);
    }

    public function value(): ?string
    {
        return $this->column ?? ($this->field instanceof ReportField ? 'field:'.$this->field->key : null);
    }

    public function isTime(): bool
    {
        return in_array($this->column, ['day', 'week', 'month'], true) || ($this->field?->isDate() ?? false);
    }

    /** @return array{0: literal-string, 1: list<string>} SQL expression and its bindings */
    public function expression(string $timezone): array
    {
        $local = "((s.submitted_at at time zone 'UTC') at time zone ?)";

        return match (true) {
            $this->column === 'status' => ['s.status', []],
            $this->column === 'submitter' => ['s.created_by::text', []],
            $this->column === 'version' => ['s.form_version_id::text', []],
            $this->column === 'day' => ["to_char({$local}, 'YYYY-MM-DD')", [$timezone]],
            $this->column === 'week' => ["to_char(date_trunc('week', {$local}), 'IYYY-\"W\"IW')", [$timezone]],
            $this->column === 'month' => ["to_char({$local}, 'YYYY-MM')", [$timezone]],
            $this->field?->isDate() ?? false => ["to_char(g.value_date, 'YYYY-MM')", []],
            $this->field instanceof ReportField => ['g.value_text', []],
            default => ['null::text', []],
        };
    }

    public function label(): string
    {
        return match (true) {
            $this->field instanceof ReportField => $this->field->label,
            $this->column !== null => (string) __('reports.group.'.$this->column),
            default => (string) __('reports.group.none'),
        };
    }

    /**
     * @param  list<string|null>  $keys
     * @return array<string, string> group key => label (missing keys fall back to the key)
     */
    public function labels(array $keys): array
    {
        $keys = array_values(array_filter($keys, is_string(...)));

        return match (true) {
            $this->column === 'status' => array_combine($keys, array_map(static fn (string $k): string => SubmissionStatus::tryFrom($k)?->label() ?? $k, $keys)),
            $this->column === 'submitter' => User::query()->whereIn('id', array_map(intval(...), $keys))->pluck('name', 'id')
                ->mapWithKeys(fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])->all(),
            $this->column === 'version' => FormVersion::query()->whereIn('id', array_map(intval(...), $keys))->pluck('version', 'id')
                ->mapWithKeys(fn (mixed $version, mixed $id): array => [(string) $id => 'v'.$version])->all(),
            $this->field?->isBoolean() ?? false => ['true' => (string) __('reports.filter.yes'), 'false' => (string) __('reports.filter.no')],
            $this->field instanceof ReportField => $this->field->options(),
            default => [],
        };
    }
}
