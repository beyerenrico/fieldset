<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms;

use App\Domain\Forms\Actions\DuplicateForm;
use App\Domain\Forms\Actions\ExportDefinition;
use App\Domain\Forms\Actions\SetFormStatus;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use App\Filament\Admin\Resources\Forms\RelationManagers\VersionsRelationManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('forms.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('forms.form.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forms.form.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('currentVersion'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('forms.form.name'))->searchable()->sortable(),
                TextColumn::make('key')->label(__('forms.form.key'))->searchable()->toggleable()->fontFamily('mono'),
                TextColumn::make('category')->label(__('forms.form.category'))->toggleable(),
                TextColumn::make('status')->label(__('forms.form.status'))->badge()
                    ->formatStateUsing(fn (FormStatus $state): string => $state->label())
                    ->color(fn (FormStatus $state): string => match ($state) {
                        FormStatus::Active => 'success', FormStatus::Draft => 'warning', FormStatus::Archived => 'gray',
                    }),
                TextColumn::make('currentVersion.version')->label(__('forms.form.current_version'))->prefix('v')->placeholder('—'),
                TextColumn::make('updated_at')->label(__('forms.form.updated_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('forms.form.status'))
                    ->options(collect(FormStatus::cases())->mapWithKeys(fn (FormStatus $s): array => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                EditAction::make()->label(__('forms.builder.title')),
                self::duplicateAction(),
                self::exportAction(),
                self::archiveAction(),
            ]);
    }

    private static function duplicateAction(): Action
    {
        return Action::make('duplicate')->label(__('forms.actions.duplicate'))->icon(Heroicon::OutlinedDocumentDuplicate)
            ->authorize(fn (Form $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Form $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                app(DuplicateForm::class)->handle($record, $actor);
            });
    }

    public static function exportAction(): Action
    {
        return Action::make('export')->label(__('forms.actions.export_json'))->icon(Heroicon::OutlinedArrowDownTray)
            ->authorize(fn (Form $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->action(function (Form $record): StreamedResponse {
                /** @var User $actor */
                $actor = auth()->user();
                $json = app(ExportDefinition::class)->handle($record, $record->currentVersion, $actor);

                return response()->streamDownload(fn (): int => print ($json), Str::slug($record->key).'.form.json', ['Content-Type' => 'application/json']);
            });
    }

    private static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(fn (Form $record): string => $record->status === FormStatus::Archived ? __('forms.actions.restore') : __('forms.actions.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)->color('gray')->requiresConfirmation()
            ->authorize(fn (Form $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Form $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                app(SetFormStatus::class)->handle($record, $record->status === FormStatus::Archived ? FormStatus::Active : FormStatus::Archived, $actor);
            });
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForms::route('/'),
            'edit' => EditForm::route('/{record}/edit'),
        ];
    }
}
