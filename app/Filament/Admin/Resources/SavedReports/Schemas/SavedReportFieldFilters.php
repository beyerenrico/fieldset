<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Schemas;

use App\Domain\Reports\Filters\FieldFilter;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Field-value filters of a saved report with typed inputs like the explorer's (FormExplorerFilters):
 * choices as a multi-select of the option labels of all versions, yes/no as a select, dates as date
 * pickers, numbers as min/max inputs. Every row is validated (an empty or malformed value is a form
 * error, never silently dropped). toForm()/fromForm() convert between stored FieldFilters and rows.
 */
final class SavedReportFieldFilters
{
    /** Decimal input: digits with "." or "," (de) as the decimal separator, as FieldFilter accepts it. */
    private const string DECIMAL_RULE = 'regex:/^-?\d{1,28}([.,]\d{1,10})?$/';

    public static function repeater(): Repeater
    {
        $kind = fn (string $expected): \Closure => fn (Get $get): bool => self::kind($get) === $expected;

        return Repeater::make('field_filters')->label(__('reports.saved.field_filters'))->columnSpanFull()->columns(3)->defaultItems(0)
            ->addActionLabel(__('reports.saved.add_field_filter'))
            ->schema([
                Select::make('key')->label(__('reports.saved.field'))->required()->live()
                    ->options(fn (Get $get): array => self::fieldOptions(SavedReportForm::catalog($get('../../form_id'))->filterable())),
                Select::make('values')->label(__('reports.saved.values_choice'))->multiple()->columnSpan(2)
                    ->options(fn (Get $get): array => self::field($get)?->options() ?? [])
                    ->visible($kind(ReportField::FILTER_SELECT))->required($kind(ReportField::FILTER_SELECT)),
                Select::make('bool')->label(__('reports.saved.value_bool'))
                    ->options(['true' => __('reports.filter.yes'), 'false' => __('reports.filter.no')])
                    ->visible($kind(ReportField::FILTER_BOOLEAN))->required($kind(ReportField::FILTER_BOOLEAN)),
                DatePicker::make('from')->label(__('reports.saved.value_from'))
                    ->visible($kind(ReportField::FILTER_DATE))->required(fn (Get $get): bool => self::kind($get) === ReportField::FILTER_DATE && blank($get('until'))),
                DatePicker::make('until')->label(__('reports.saved.value_until'))->minDate(fn (Get $get): mixed => $get('from'))
                    ->visible($kind(ReportField::FILTER_DATE)),
                TextInput::make('min')->label(__('reports.saved.value_min'))->rules([self::DECIMAL_RULE])
                    ->validationMessages(['regex' => __('reports.saved.invalid_number')])
                    ->visible($kind(ReportField::FILTER_NUMBER))->required(fn (Get $get): bool => self::kind($get) === ReportField::FILTER_NUMBER && blank($get('max'))),
                TextInput::make('max')->label(__('reports.saved.value_max'))->rules([self::DECIMAL_RULE])
                    ->validationMessages(['regex' => __('reports.saved.invalid_number')])
                    ->visible($kind(ReportField::FILTER_NUMBER)),
            ]);
    }

    /**
     * Stored filters → repeater rows.
     *
     * @param  array<array-key, mixed>  $fields  FieldFilter arrays
     * @return list<array<string, mixed>>
     */
    public static function toForm(array $fields, FieldCatalog $catalog): array
    {
        $rows = [];
        foreach ($fields as $data) {
            $filter = is_array($data) ? FieldFilter::fromArray($data) : null;
            if ($filter instanceof FieldFilter) {
                $rows[] = ['key' => $filter->key, ...self::rowValues($filter, $catalog->get($filter->key))];
            }
        }

        return $rows;
    }

    /**
     * Repeater rows → FieldFilter arrays (the operator follows the field's type).
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    public static function fromForm(array $rows, FieldCatalog $catalog): array
    {
        $fields = [];
        foreach ($rows as $row) {
            $field = is_array($row) && is_string($row['key'] ?? null) ? $catalog->get($row['key']) : null;
            if ($field instanceof ReportField && is_array($row)) {
                $fields[] = self::filter($field, $row);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private static function rowValues(FieldFilter $filter, ?ReportField $field): array
    {
        [$first, $second] = [$filter->values[0] ?? null, $filter->values[1] ?? null];

        return match (true) {
            $filter->operator === FieldFilter::IN => ['values' => $filter->values],
            $filter->operator === FieldFilter::IS => ['bool' => $first === true ? 'true' : 'false'],
            ($field?->isDate() ?? (is_string($first ?? $second) && FieldFilter::isDay($first ?? $second))) => ['from' => $first, 'until' => $second],
            default => ['min' => $first, 'max' => $second],
        };
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array{key: string, operator: string, values: list<mixed>}
     */
    private static function filter(ReportField $field, array $row): array
    {
        $decimal = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? str_replace(',', '.', trim($v)) : null;
        $day = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? substr($v, 0, 10) : null;

        return match ($field->filterKind()) {
            ReportField::FILTER_SELECT => ['key' => $field->key, 'operator' => FieldFilter::IN, 'values' => array_values(is_array($row['values'] ?? null) ? $row['values'] : [])],
            ReportField::FILTER_BOOLEAN => ['key' => $field->key, 'operator' => FieldFilter::IS, 'values' => [($row['bool'] ?? null) === 'true']],
            ReportField::FILTER_DATE => ['key' => $field->key, 'operator' => FieldFilter::BETWEEN, 'values' => [$day($row['from'] ?? null), $day($row['until'] ?? null)]],
            default => ['key' => $field->key, 'operator' => FieldFilter::BETWEEN, 'values' => [$decimal($row['min'] ?? null), $decimal($row['max'] ?? null)]],
        };
    }

    private static function field(Get $get): ?ReportField
    {
        $key = $get('key');

        return is_string($key) ? SavedReportForm::catalog($get('../../form_id'))->get($key) : null;
    }

    private static function kind(Get $get): ?string
    {
        return self::field($get)?->filterKind();
    }

    /**
     * @param  list<ReportField>  $fields
     * @return array<string, string>
     */
    public static function fieldOptions(array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (ReportField $f): array => [$f->key => $f->repeater === null ? $f->label : $f->key.' ('.$f->label.')'])->all();
    }
}
