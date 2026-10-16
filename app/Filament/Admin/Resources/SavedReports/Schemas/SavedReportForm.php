<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Schemas;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Enums\ReportSchedule;
use App\Domain\Reports\Enums\ReportVisibility;
use App\Domain\Reports\Filters\DateRange;
use App\Domain\Reports\Filters\FieldFilter;
use App\Domain\Reports\ReportForms;
use App\Domain\Reports\Saved\ReportGrouping;
use App\Domain\Reports\Saved\SaveReport;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Submissions\Enums\SubmissionStatus;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Saved report form. Group-by and metric fields depend on the chosen form (its FieldCatalog); field
 * filters are entered as lines (choices: one value per line; ranges: from and until). toForm()/fromForm()
 * convert between the stored filters and the form state; SaveReport validates everything again.
 */
final class SavedReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make()->columnSpanFull()->columns(2)->schema([
                TextInput::make('name')->label(__('reports.saved.name'))->required()->maxLength(120),
                Select::make('form_id')->label(__('reports.saved.form'))->required()->searchable()->live()
                    ->options(fn (): array => app(ReportForms::class)->options(self::user())),
                Select::make('group_by')->label(__('reports.saved.group_by'))->placeholder(__('reports.group.none'))
                    ->options(fn (Get $get): array => self::groupOptions(self::catalog($get('form_id')))),
                Select::make('metric')->label(__('reports.saved.metric'))->required()->live()->default(ReportMetric::Count->value)
                    ->options(self::enumOptions(ReportMetric::cases())),
                Select::make('metric_key')->label(__('reports.saved.metric_key'))->helperText(__('reports.saved.metric_key_help'))
                    ->visible(fn (Get $get): bool => in_array($get('metric'), [ReportMetric::Sum->value, ReportMetric::Avg->value], true))
                    ->required(fn (Get $get): bool => in_array($get('metric'), [ReportMetric::Sum->value, ReportMetric::Avg->value], true))
                    ->options(fn (Get $get): array => self::fieldOptions(self::catalog($get('form_id'))->numeric())),
                Select::make('visibility')->label(__('reports.saved.visibility'))->required()->default(ReportVisibility::Private->value)
                    ->options(self::enumOptions(ReportVisibility::cases())),
            ]),
            Section::make(__('reports.saved.filters'))->columnSpanFull()->columns(3)->schema([
                Select::make('filters.period')->label(__('reports.saved.period'))->placeholder(__('reports.period.all'))
                    ->options(collect(DateRange::PERIODS)->mapWithKeys(fn (string $p): array => [$p => __('reports.period.'.$p)])->all()),
                Select::make('filters.statuses')->label(__('reports.saved.statuses'))->multiple()->helperText(__('reports.saved.statuses_help'))
                    ->options(self::statusOptions()),
                Select::make('filters.groups')->label(__('reports.saved.groups'))->multiple()
                    ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->map(fn (mixed $n): string => (string) $n)->all()),
                Repeater::make('field_filters')->label(__('reports.saved.field_filters'))->columnSpanFull()->columns(2)->defaultItems(0)
                    ->schema([
                        Select::make('key')->label(__('reports.saved.field'))->required()
                            ->options(fn (Get $get): array => self::fieldOptions(self::catalog($get('../../form_id'))->filterable())),
                        Textarea::make('values')->label(__('reports.saved.values'))->rows(2)->helperText(__('reports.saved.values_help')),
                    ]),
            ]),
            Section::make(__('reports.saved.schedule'))->columnSpanFull()->columns(2)->schema([
                Select::make('schedule')->label(__('reports.saved.schedule'))->required()->live()->default(ReportSchedule::None->value)
                    ->options(self::enumOptions(ReportSchedule::cases())),
                Select::make('recipients')->label(__('reports.saved.recipients'))->multiple()->helperText(__('reports.saved.recipients_help'))
                    ->visible(fn (Get $get): bool => $get('schedule') !== ReportSchedule::None->value)
                    ->options(fn (): array => app(SaveReport::class)->recipientOptions()),
            ]),
        ]);
    }

    /**
     * Stored filters → form state (field filters as lines).
     *
     * @param  array<string, mixed>  $data  model attributes
     * @return array<string, mixed>
     */
    public static function toForm(array $data): array
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $data['field_filters'] = array_values(array_map(static fn (mixed $f): array => [
            'key' => is_array($f) ? ($f['key'] ?? null) : null,
            'values' => is_array($f) && is_array($f['values'] ?? null)
                ? implode("\n", array_map(static fn (mixed $v): string => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $f['values'])) : '',
        ], is_array($filters['fields'] ?? null) ? $filters['fields'] : []));

        return $data;
    }

    /**
     * Form state → SaveReport input (field filters with the operator of the field's type).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(array $data): array
    {
        $catalog = self::catalog($data['form_id'] ?? null);
        $fields = [];
        foreach (is_array($data['field_filters'] ?? null) ? $data['field_filters'] : [] as $row) {
            $field = is_array($row) && is_string($row['key'] ?? null) ? $catalog->get($row['key']) : null;
            $lines = is_array($row) ? array_map(trim(...), explode("\n", (string) ($row['values'] ?? ''))) : [];
            if ($field instanceof ReportField) {
                $fields[] = self::fieldFilter($field, $lines);
            }
        }
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        unset($data['field_filters']);

        return [...$data, 'filters' => [...$filters, 'fields' => $fields]];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private static function fieldFilter(ReportField $field, array $lines): array
    {
        return match ($field->filterKind()) {
            ReportField::FILTER_SELECT => ['key' => $field->key, 'operator' => FieldFilter::IN, 'values' => array_values(array_filter($lines, fn (string $l): bool => $l !== ''))],
            ReportField::FILTER_BOOLEAN => ['key' => $field->key, 'operator' => FieldFilter::IS, 'values' => [in_array(mb_strtolower($lines[0] ?? ''), ['true', 'yes', 'ja', '1'], true)]],
            default => ['key' => $field->key, 'operator' => FieldFilter::BETWEEN, 'values' => [
                ($lines[0] ?? '') === '' ? null : str_replace(',', '.', $lines[0]), ($lines[1] ?? '') === '' ? null : str_replace(',', '.', $lines[1]),
            ]],
        };
    }

    public static function catalog(mixed $formId): FieldCatalog
    {
        $form = is_numeric($formId) ? app(ReportForms::class)->find(self::user(), (int) $formId) : null;

        return $form instanceof Form ? FieldCatalog::forForm($form) : FieldCatalog::fromDefinitions([]);
    }

    /** @return array<string, string> */
    private static function groupOptions(FieldCatalog $catalog): array
    {
        $options = collect(ReportGrouping::OPTIONS)->mapWithKeys(fn (string $o): array => [$o => __('reports.group.'.$o)])->all();
        foreach ($catalog->groupable() as $field) {
            $options['field:'.$field->key] = __('reports.group.field', ['label' => $field->label]);
        }

        return $options;
    }

    /**
     * @param  list<ReportField>  $fields
     * @return array<string, string>
     */
    private static function fieldOptions(array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (ReportField $f): array => [$f->key => $f->repeater === null ? $f->label : $f->key.' ('.$f->label.')'])->all();
    }

    /**
     * @param  list<ReportMetric|ReportVisibility|ReportSchedule>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn (ReportMetric|ReportVisibility|ReportSchedule $c): array => [$c->value => $c->label()])->all();
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(SubmissionStatus::cases())->reject(fn (SubmissionStatus $s): bool => $s === SubmissionStatus::Draft)
            ->mapWithKeys(fn (SubmissionStatus $s): array => [$s->value => $s->label()])->all();
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
