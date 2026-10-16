<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Tables;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Inbox list: newest submissions first, drafts hidden unless the status filter asks for them. */
final class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        $tz = SubmissionResource::displayTimezone();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['form:id,name', 'formVersion:id,version', 'creator:id,name']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('submitted_at desc nulls last')->orderByDesc('created_at'))
            ->emptyStateHeading(__('inbox.empty'))
            ->columns([
                TextColumn::make('number')->label(__('inbox.column.number'))->searchable()->sortable()->placeholder('—')->fontFamily('mono'),
                TextColumn::make('form.name')->label(__('inbox.column.form')),
                TextColumn::make('formVersion.version')->label(__('inbox.column.version'))->prefix('v')->toggleable(),
                TextColumn::make('status')->label(__('inbox.column.status'))->badge()
                    ->formatStateUsing(fn (SubmissionStatus $state): string => $state->label())
                    ->color(fn (SubmissionStatus $state): string => SubmissionResource::statusColor($state)),
                TextColumn::make('creator.name')->label(__('inbox.column.submitter'))->placeholder('—'),
                TextColumn::make('submitted_at')->label(__('inbox.column.submitted_at'))->dateTime()->timezone($tz)->sortable()->placeholder('—'),
                TextColumn::make('updated_at')->label(__('inbox.column.updated_at'))->since()->timezone($tz)->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('inbox.column.status'))->multiple()
                    ->options(collect(SubmissionStatus::cases())->mapWithKeys(fn (SubmissionStatus $s): array => [$s->value => $s->label()])->all())
                    ->default(array_values(array_map(fn (SubmissionStatus $s): string => $s->value, array_filter(
                        SubmissionStatus::cases(),
                        fn (SubmissionStatus $s): bool => $s !== SubmissionStatus::Draft,
                    )))),
                SelectFilter::make('form_id')->label(__('inbox.column.form'))->searchable()
                    ->options(fn (): array => self::formOptions()),
                SelectFilter::make('created_by')->label(__('inbox.column.submitter'))->searchable()
                    ->options(fn (): array => self::submitterOptions()),
                self::dateFilter($tz),
            ])
            ->recordActions([ViewAction::make()]);
    }

    private static function dateFilter(string $tz): Filter
    {
        $day = fn (string $date): Carbon => Carbon::parse($date, $tz);

        return Filter::make('submitted_at')
            ->schema([
                DatePicker::make('from')->label(__('inbox.filter.from')),
                DatePicker::make('until')->label(__('inbox.filter.until')),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(is_string($data['from'] ?? null) && $data['from'] !== '', fn (Builder $q): Builder => $q->where('submitted_at', '>=', $day((string) $data['from'])->startOfDay()->utc()))
                ->when(is_string($data['until'] ?? null) && $data['until'] !== '', fn (Builder $q): Builder => $q->where('submitted_at', '<=', $day((string) $data['until'])->endOfDay()->utc())))
            ->indicateUsing(function (array $data): array {
                $indicators = [];
                foreach (['from', 'until'] as $field) {
                    if (is_string($data[$field] ?? null) && $data[$field] !== '') {
                        $date = Carbon::parse($data[$field])->settings(['locale' => app()->getLocale()])->isoFormat('L');
                        $indicators[] = Indicator::make(__("inbox.filter.{$field}_indicator", ['date' => $date]))->removeField($field);
                    }
                }

                return $indicators;
            });
    }

    /** @return array<int, string> forms that have visible submissions */
    private static function formOptions(): array
    {
        return Form::query()->whereIn('id', self::visible()->select('form_id'))->orderBy('name')->pluck('name', 'id')
            ->map(fn (mixed $name): string => (string) $name)->all();
    }

    /** @return array<int, string> users who created visible submissions */
    private static function submitterOptions(): array
    {
        return User::query()->whereIn('id', self::visible()->whereNotNull('created_by')->select('created_by'))->orderBy('name')->pluck('name', 'id')
            ->map(fn (mixed $name): string => (string) $name)->all();
    }

    /** @return Builder<Submission> */
    private static function visible(): Builder
    {
        return SubmissionResource::getEloquentQuery();
    }
}
