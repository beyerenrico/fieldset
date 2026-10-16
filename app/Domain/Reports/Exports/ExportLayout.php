<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Reports\Exports\Writers\DateCell;
use App\Domain\Reports\Exports\Writers\DecimalCell;
use App\Domain\Reports\Exports\Writers\ExportSheet;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Reports\Schema\ReportValue;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Columns of an export (BUILD_PROMPT §8.4): sheet 1 has number, status, submitter, submitted at, version
 * and one column per top-level key of all versions (repeaters: their row count); every repeater gets its
 * own sheet whose rows link back by submission number and row number (1-based; nested rows "1.2"). Every
 * money key is followed by a currency column with the currency of the submission's form version (review
 * M1); numbers and money are DecimalCells (exact strings).
 */
final readonly class ExportLayout
{
    /**
     * @param  list<ReportField>  $fields
     * @param  array<string, ReportField>  $repeaters
     * @param  array<string, list<ReportField>>  $children
     */
    private function __construct(private array $fields, private array $repeaters, private array $children, private string $timezone) {}

    public static function for(FieldCatalog $catalog, string $timezone): self
    {
        $repeaters = $children = [];
        foreach ($catalog->repeaters() as $repeater) {
            $repeaters[$repeater->key] = $repeater;
            $children[$repeater->key] = $catalog->children($repeater->key);
        }

        return new self($catalog->topLevel(), $repeaters, $children, $timezone);
    }

    public function main(): ExportSheet
    {
        return new ExportSheet((string) __('reports.export.sheet'), [
            ...$this->baseHeaders(), (string) __('reports.column.submitter'), (string) __('reports.column.submitted_at'),
            (string) __('reports.column.version'), ...$this->headers($this->fields),
        ]);
    }

    /** @return array<string, ExportSheet> repeater key => sheet */
    public function repeaterSheets(): array
    {
        $sheets = [];
        foreach ($this->repeaters as $key => $repeater) {
            $sheets[$key] = new ExportSheet($this->repeaterName($repeater), [
                (string) __('reports.column.number'), (string) __('reports.column.row'), ...$this->headers($this->children[$key]),
            ]);
        }

        return $sheets;
    }

    /** @return list<string|int|bool|DateCell|DecimalCell|null> */
    public function submissionCells(ExportRecord $record): array
    {
        $s = $record->submission;
        $cells = [
            $s->number, $s->status->label(), $s->creator?->name,
            $s->submitted_at === null ? null : new DateCell(DateTimeImmutable::createFromInterface($s->submitted_at)->setTimezone(new DateTimeZone($this->timezone)), true),
            $s->formVersion->version,
        ];
        foreach ($this->fields as $field) {
            $text = $record->values[$field->key] ?? null;
            $cells[] = $field->isRepeater() ? ($record->rowCounts[$field->key] ?? 0) : $this->value($field, $text);
            if ($field->isMoney()) {
                $cells[] = $text === null ? null : $field->currencyFor($s->form_version_id);
            }
        }

        return $cells;
    }

    /** @return list<list<string|int|bool|DateCell|DecimalCell|null>> */
    public function repeaterRows(ExportRecord $record, string $repeaterKey): array
    {
        $rows = $record->rows[$repeaterKey] ?? [];
        uksort($rows, strnatcmp(...));
        $lines = [];
        foreach ($rows as $rowId => $values) {
            $line = [$record->submission->number, (string) $rowId];
            foreach ($this->children[$repeaterKey] ?? [] as $field) {
                $text = $values[$field->key] ?? null;
                $line[] = $this->value($field, $text);
                if ($field->isMoney()) {
                    $line[] = $text === null ? null : $field->currencyFor($record->submission->form_version_id);
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** @return list<string> */
    public function repeaterKeys(): array
    {
        return array_keys($this->repeaters);
    }

    /** @return list<string> */
    private function baseHeaders(): array
    {
        return [(string) __('reports.column.number'), (string) __('reports.column.status')];
    }

    /**
     * @param  list<ReportField>  $fields
     * @return list<string> one header per field, money followed by its currency column
     */
    private function headers(array $fields): array
    {
        $headers = [];
        foreach ($fields as $field) {
            $headers[] = $field->label;
            if ($field->isMoney()) {
                $headers[] = (string) __('reports.export.currency_column', ['label' => $field->label]);
            }
        }

        return $headers;
    }

    private function value(ReportField $field, ?string $text): string|int|bool|DateCell|DecimalCell|null
    {
        if ($field->type === 'date' && $text !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

            return $date === false ? $text : new DateCell($date, false);
        }
        $value = ReportValue::export($field, $text);

        return $field->isNumeric() && is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? new DecimalCell($value) : $value;
    }

    private function repeaterName(ReportField $repeater): string
    {
        $parent = $repeater->repeater === null ? null : ($this->repeaters[$repeater->repeater] ?? null);

        return $parent instanceof ReportField ? $this->repeaterName($parent).' › '.$repeater->label : $repeater->label;
    }
}
