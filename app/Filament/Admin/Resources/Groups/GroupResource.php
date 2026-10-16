<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Groups;

use App\Domain\Identity\Models\Group;
use App\Filament\Admin\Resources\Groups\Pages\ManageGroups;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation.team');
    }

    public static function getModelLabel(): string
    {
        return __('identity.group.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('identity.group.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('identity.group.name'))->required()->maxLength(120)
                ->scopedUnique(ignoreRecord: true),
            Textarea::make('description')->label(__('identity.group.description'))->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('identity.group.name'))->searchable()->sortable(),
                TextColumn::make('users_count')->label(__('identity.group.members'))->counts('users'),
                TextColumn::make('description')->label(__('identity.group.description'))->limit(60),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGroups::route('/')];
    }
}
