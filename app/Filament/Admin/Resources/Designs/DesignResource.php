<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Designs;

use App\Domain\Documents\Designs\Actions\SetDefaultDesign;
use App\Domain\Documents\Models\Design;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Designs\Pages\EditDesign;
use App\Filament\Admin\Resources\Designs\Pages\ListDesigns;
use App\Filament\Admin\Resources\Designs\RelationManagers\DesignVersionsRelationManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Document designs (BUILD_PROMPT §8.5): versioned like forms, selected by `settings.document.design`. */
class DesignResource extends Resource
{
    protected static ?string $model = Design::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('forms.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('documents.designs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documents.designs.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('currentVersion'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('documents.designs.name'))->searchable()->sortable(),
                TextColumn::make('key')->label(__('documents.designs.key'))->fontFamily('mono')->searchable(),
                IconColumn::make('is_default')->label(__('documents.designs.is_default'))->boolean(),
                TextColumn::make('currentVersion.version')->label(__('documents.designs.current_version'))->prefix('v')->placeholder('—'),
                TextColumn::make('updated_at')->label(__('documents.designs.updated_at'))->since()->sortable(),
            ])
            ->recordActions([EditAction::make(), self::setDefaultAction()]);
    }

    private static function setDefaultAction(): Action
    {
        return Action::make('setDefault')->label(__('documents.actions.set_default'))->icon(Heroicon::OutlinedStar)->color('gray')
            ->visible(fn (Design $record): bool => ! $record->is_default)
            ->authorize(fn (Design $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->action(function (Design $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                app(SetDefaultDesign::class)->handle($record, $actor);
            });
    }

    public static function getRelations(): array
    {
        return [DesignVersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDesigns::route('/'),
            'edit' => EditDesign::route('/{record}/edit'),
        ];
    }
}
