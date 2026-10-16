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
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\ReportForms;
use App\Domain\Reports\Saved\ReportGrouping;
use App\Domain\Reports\Saved\SaveReport;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Filament\Admin\Pages\FormExplorerFilters;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Saved report form. Group-by, metric, submitter and field-filter inputs depend on the chosen form (its
 * FieldCatalog). Every stored filter has a field (review H1): period or a custom date range (calendar
 * days in the time zone they were chosen in), statuses, submitters, groups, search and typed field
 * filters (SavedReportFieldFilters), so saving the form never drops filters set in the explorer.
 * toForm()/fromForm() convert between the stored filters and the form state; SaveReport validates again.
 */
final class SavedReportForm
{
    /** Period option that shows the from/until date pickers. */
    public const string CUSTOM_PERIOD = 'custom';

    public static function configure(Schema $schema): Schema
    {
        $custom = fn (Get $get): bool => $get('filters.period') === self::CUSTOM_PERIOD;

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
                    ->options(fn (Get $get): array => SavedReportFieldFilters::fieldOptions(self::catalog($get('form_id'))->numeric())),
                Select::make('visibility')->label(__('reports.saved.visibility'))->required()->default(ReportVisibility::Private->value)
                    ->options(self::enumOptions(ReportVisibility::cases())),
            ]),
            Section::make(__('reports.saved.filters'))->columnSpanFull()->columns(3)->schema([
                Select::make('filters.period')->label(__('reports.saved.period'))->placeholder(__('reports.period.all'))->live()
                    ->options([
                        ...collect(DateRange::PERIODS)->mapWithKeys(fn (string $p): array => [$p => __('reports.period.'.$p)])->all(),
                        self::CUSTOM_PERIOD => __('reports.period.custom'),
                    ]),
                DatePicker::make('filters.from')->label(__('reports.filter.submitted_from'))->visible($custom)
                    ->required(fn (Get $get): bool => $custom($get) && blank($get('filters.until')))
                    ->helperText(fn (Get $get): string => __('reports.saved.days_timezone', ['timezone' => self::daysTimezone($get('filters.timezone'))])),
                DatePicker::make('filters.until')->label(__('reports.filter.submitted_until'))->visible($custom)
                    ->minDate(fn (Get $get): mixed => $get('filters.from')),
                Hidden::make('filters.timezone'),
                Select::make('filters.statuses')->label(__('reports.saved.statuses'))->multiple()->helperText(__('reports.saved.statuses_help'))
                    ->options(self::statusOptions()),
                Select::make('filters.submitters')->label(__('reports.column.submitter'))->multiple()->searchable()
                    ->options(fn (Get $get): array => self::submitterOptions($get('form_id'))),
                Select::make('filters.groups')->label(__('reports.saved.groups'))->multiple()
                    ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->map(fn (mixed $n): string => (string) $n)->all()),
                TextInput::make('filters.search')->label(__('reports.saved.search'))->helperText(__('reports.saved.search_help'))->maxLength(100),
                SavedReportFieldFilters::repeater(),
            ]),
            Section::make(__('reports.saved.schedule_section'))->columnSpanFull()->columns(2)->schema([
                Select::make('schedule')->label(__('reports.saved.schedule'))->required()->live()->default(ReportSchedule::None->value)
                    ->helperText(__('reports.saved.schedule_help'))
                    ->options(self::enumOptions(ReportSchedule::cases())),
                Select::make('recipients')->label(__('reports.saved.recipients'))->multiple()->helperText(__('reports.saved.recipients_help'))
                    ->visible(fn (Get $get): bool => $get('schedule') !== ReportSchedule::None->value)
                    ->options(fn (): array => app(SaveReport::class)->recipientOptions()),
            ]),
        ]);
    }

    /**
     * Stored filters → form state (custom range as period "custom", field filters as typed rows).
     *
     * @param  array<string, mixed>  $data  model attributes
     * @return array<string, mixed>
     */
    public static function toForm(array $data): array
    {
        $filters = ReportFilters::fromArray(is_array($data['filters'] ?? null) ? $data['filters'] : []);
        $state = $filters->toArray();
        $state['period'] = $filters->period ?? ($filters->from !== null || $filters->until !== null ? self::CUSTOM_PERIOD : null);
        unset($state['fields']);
        $data['filters'] = $state;
        $data['field_filters'] = SavedReportFieldFilters::toForm($filters->toArray()['fields'], self::catalog($data['form_id'] ?? null));

        return $data;
    }

    /**
     * Form state → SaveReport input: a relative period clears the custom days; custom days keep the time
     * zone they were chosen in (the editor's display time zone for new ones).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(array $data): array
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $custom = ($filters['period'] ?? null) === self::CUSTOM_PERIOD;
        $filters = [
            ...$filters,
            'period' => $custom ? null : ($filters['period'] ?? null),
            'from' => $custom ? self::day($filters['from'] ?? null) : null,
            'until' => $custom ? self::day($filters['until'] ?? null) : null,
            'timezone' => $custom ? self::daysTimezone($filters['timezone'] ?? null) : null,
            'fields' => SavedReportFieldFilters::fromForm(is_array($data['field_filters'] ?? null) ? $data['field_filters'] : [], self::catalog($data['form_id'] ?? null)),
        ];
        unset($data['field_filters']);

        return [...$data, 'filters' => $filters];
    }

    /** The catalog of a form the current user may report on (memoized per request and form). */
    public static function catalog(mixed $formId): FieldCatalog
    {
        $id = is_numeric($formId) ? (int) $formId : null;
        $user = self::user();

        return once(function () use ($id, $user): FieldCatalog {
            $form = $id === null ? null : app(ReportForms::class)->find($user, $id);

            return $form instanceof Form ? FieldCatalog::forForm($form) : FieldCatalog::fromDefinitions([]);
        });
    }

    private static function daysTimezone(mixed $timezone): string
    {
        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : SubmissionResource::displayTimezone();
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** @return array<int, string> */
    private static function submitterOptions(mixed $formId): array
    {
        $form = is_numeric($formId) ? app(ReportForms::class)->find(self::user(), (int) $formId) : null;

        return $form instanceof Form ? FormExplorerFilters::submitterOptions($form, self::user()) : [];
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
