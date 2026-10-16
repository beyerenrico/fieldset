<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports;

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Enums\ReportSchedule;
use App\Domain\Reports\Enums\ReportVisibility;
use App\Domain\Reports\Models\SavedReport;
use App\Filament\Admin\Resources\SavedReports\Actions\PinActions;
use App\Filament\Admin\Resources\SavedReports\Pages\CreateSavedReport;
use App\Filament\Admin\Resources\SavedReports\Pages\EditSavedReport;
use App\Filament\Admin\Resources\SavedReports\Pages\ListSavedReports;
use App\Filament\Admin\Resources\SavedReports\Pages\ViewSavedReport;
use App\Filament\Admin\Resources\SavedReports\Schemas\SavedReportForm;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saved reports (BUILD_PROMPT §8.4, M5-T03): form + filters + group-by + metric as a table and a chart,
 * private or shared with everyone holding `reports.view`, optionally emailed weekly or monthly.
 */
class SavedReportResource extends Resource
{
    protected static ?string $model = SavedReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('reports.saved.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.saved.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.saved.navigation');
    }

    /** @return Builder<SavedReport> own reports and those shared with the organization */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return SavedReport::query()->with(['form:id,name', 'creator:id,name'])
            ->where(fn (Builder $q) => $q->where('visibility', ReportVisibility::Organization->value)->orWhere('created_by', $user->id));
    }

    public static function form(Schema $schema): Schema
    {
        return SavedReportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => PinActions::withPinnedFlag($query))
            ->columns([
                TextColumn::make('name')->label(__('reports.saved.name'))->searchable()->sortable(),
                TextColumn::make('form.name')->label(__('reports.saved.form')),
                TextColumn::make('metric')->label(__('reports.saved.metric'))
                    ->formatStateUsing(fn (ReportMetric $state): string => $state->label()),
                TextColumn::make('visibility')->label(__('reports.saved.visibility'))->badge()
                    ->formatStateUsing(fn (ReportVisibility $state): string => $state->label()),
                TextColumn::make('schedule')->label(__('reports.saved.schedule'))
                    ->formatStateUsing(fn (ReportSchedule $state): string => $state->label()),
                TextColumn::make('creator.name')->label(__('reports.saved.created_by'))->placeholder('—'),
                TextColumn::make('updated_at')->label(__('forms.form.updated_at'))->since()->sortable(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), PinActions::pin(), PinActions::unpin(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSavedReports::route('/'),
            'create' => CreateSavedReport::route('/create'),
            'view' => ViewSavedReport::route('/{record}'),
            'edit' => EditSavedReport::route('/{record}/edit'),
        ];
    }
}
