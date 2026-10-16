<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Filters\FieldFilter;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Filters\ReportScope;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Submissions\Enums\SubmissionStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Explorer filters (BUILD_PROMPT §8.4): submission date, status, submitter, group and one filter per
 * select/boolean/date/number key. Each filter applies itself through ReportScope; toReportFilters()
 * turns the same table state into ReportFilters for exports and saved reports, so both select the
 * same rows. Statuses default to everything but void; clearing the filter shows void too.
 */
final class FormExplorerFilters
{
    /** @return list<BaseFilter> */
    public static function make(Form $form, FieldCatalog $catalog, string $timezone, User $user): array
    {
        $scope = app(ReportScope::class);

        return [
            Filter::make('submitted')->schema([
                DatePicker::make('from')->label(__('reports.filter.submitted_from')),
                DatePicker::make('until')->label(__('reports.filter.submitted_until')),
            ])->columns(2)->columnSpan(2)
                ->query(fn (Builder $query, array $data) => $scope->range($query, self::toReportFilters(['submitted' => $data], null, $catalog)->dateRange($timezone))),
            SelectFilter::make('status')->label(__('reports.column.status'))->multiple()
                ->options(self::statusOptions())->default(ReportFilters::fromArray([])->effectiveStatuses())
                ->query(fn (Builder $query, array $data) => $scope->statuses($query, self::statuses($data))),
            SelectFilter::make('submitter')->label(__('reports.column.submitter'))->multiple()->searchable()
                ->options(fn (): array => self::submitterOptions($scope, $form, $user))
                ->query(fn (Builder $query, array $data) => $scope->submitters($query, self::ids($data))),
            SelectFilter::make('group')->label(__('reports.filter.group'))->multiple()
                ->options(fn (): array => Group::query()->orderBy('name')->pluck('name', 'id')->map(fn (mixed $n): string => (string) $n)->all())
                ->query(fn (Builder $query, array $data) => $scope->groups($query, $form, self::ids($data))),
            ...array_map(fn (ReportField $field): BaseFilter => self::fieldFilter($field, $form, $scope), $catalog->filterable()),
        ];
    }

    /** @param  array<string, mixed>  $state  Livewire tableFilters */
    public static function toReportFilters(array $state, ?string $search, FieldCatalog $catalog): ReportFilters
    {
        $submitted = is_array($state['submitted'] ?? null) ? $state['submitted'] : [];
        $fields = [];
        foreach ($catalog->filterable() as $field) {
            $filter = self::fieldFilterFromState($field, is_array($state['field_'.$field->key] ?? null) ? $state['field_'.$field->key] : []);
            if ($filter instanceof FieldFilter) {
                $fields[] = $filter;
            }
        }

        return ReportFilters::fromArray([
            'from' => $submitted['from'] ?? null, 'until' => $submitted['until'] ?? null,
            'statuses' => self::statuses(is_array($state['status'] ?? null) ? $state['status'] : []),
            'submitters' => self::ids(is_array($state['submitter'] ?? null) ? $state['submitter'] : []),
            'groups' => self::ids(is_array($state['group'] ?? null) ? $state['group'] : []),
            'fields' => array_map(fn (FieldFilter $f): array => $f->toArray(), $fields),
            'search' => $search,
        ]);
    }

    private static function fieldFilter(ReportField $field, Form $form, ReportScope $scope): BaseFilter
    {
        $apply = function (Builder $query, array $data) use ($field, $form, $scope): void {
            $filter = self::fieldFilterFromState($field, $data);
            if ($filter instanceof FieldFilter) {
                $scope->field($query, $form, $filter, $field);
            }
        };
        $name = 'field_'.$field->key;

        return match ($field->filterKind()) {
            ReportField::FILTER_SELECT => SelectFilter::make($name)->label($field->label)->multiple()->options($field->options())->query($apply),
            ReportField::FILTER_BOOLEAN => SelectFilter::make($name)->label($field->label)
                ->options(['true' => __('reports.filter.yes'), 'false' => __('reports.filter.no')])->query($apply),
            ReportField::FILTER_DATE => Filter::make($name)->schema([
                DatePicker::make('from')->label($field->label.' '.__('reports.filter.from')),
                DatePicker::make('until')->label($field->label.' '.__('reports.filter.until')),
            ])->columns(2)->columnSpan(2)->query($apply),
            default => Filter::make($name)->schema([
                TextInput::make('min')->label($field->label.' '.__('reports.filter.min'))->rule('regex:/^-?\d+([.,]\d+)?$/'),
                TextInput::make('max')->label($field->label.' '.__('reports.filter.max'))->rule('regex:/^-?\d+([.,]\d+)?$/'),
            ])->columns(2)->columnSpan(2)->query($apply),
        };
    }

    /** @param  array<array-key, mixed>  $data */
    private static function fieldFilterFromState(ReportField $field, array $data): ?FieldFilter
    {
        $decimal = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? str_replace(',', '.', trim($v)) : null;

        return match ($field->filterKind()) {
            ReportField::FILTER_SELECT => FieldFilter::fromArray(['key' => $field->key, 'operator' => FieldFilter::IN, 'values' => $data['values'] ?? []]),
            ReportField::FILTER_BOOLEAN => in_array($data['value'] ?? null, ['true', 'false'], true)
                ? new FieldFilter($field->key, FieldFilter::IS, [$data['value'] === 'true']) : null,
            ReportField::FILTER_DATE => FieldFilter::fromArray(['key' => $field->key, 'operator' => FieldFilter::BETWEEN, 'values' => [$data['from'] ?? null, $data['until'] ?? null]]),
            ReportField::FILTER_NUMBER => FieldFilter::fromArray(['key' => $field->key, 'operator' => FieldFilter::BETWEEN, 'values' => [$decimal($data['min'] ?? null), $decimal($data['max'] ?? null)]]),
            default => null,
        };
    }

    /**
     * The selected statuses; none selected means every status except draft (void included).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function statuses(array $data): array
    {
        $selected = ReportFilters::fromArray(['statuses' => is_array($data['values'] ?? null) ? $data['values'] : []])->statuses;

        return $selected !== [] ? $selected : array_keys(self::statusOptions());
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<int>
     */
    private static function ids(array $data): array
    {
        return ReportFilters::fromArray(['submitters' => is_array($data['values'] ?? null) ? $data['values'] : []])->submitters;
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (SubmissionStatus::cases() as $status) {
            if ($status !== SubmissionStatus::Draft) {
                $options[$status->value] = $status->label();
            }
        }

        return $options;
    }

    /** @return array<int, string> users who created visible submissions of the form */
    private static function submitterOptions(ReportScope $scope, Form $form, User $user): array
    {
        $creators = $scope->base($user, $form)->whereNotNull('submissions.created_by')->select('submissions.created_by');

        return User::query()->whereIn('id', $creators)->orderBy('name')->pluck('name', 'id')->map(fn (mixed $n): string => (string) $n)->all();
    }
}
