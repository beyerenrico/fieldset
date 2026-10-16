<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Roles;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use App\Filament\Admin\Support\OrganizationOptions;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/** Custom roles as a checkbox list of capabilities (ADR 0017). */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation.team');
    }

    public static function getModelLabel(): string
    {
        return __('identity.role.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('identity.role.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('identity.role.name'))->required()->maxLength(60)
                ->disabled(fn (?Role $record): bool => $record?->isSystemRole() ?? false)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule
                    ->where('organization_id', app(TenantContext::class)->require())
                    ->where('guard_name', 'web')),
            CheckboxList::make('permissions')->label(__('identity.role.permissions'))
                ->relationship('permissions', 'name')
                ->getOptionLabelFromRecordUsing(fn (PermissionModel $record): string => Permission::tryFrom($record->name)?->label() ?? $record->name)
                ->columns(2)->bulkToggleable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('identity.role.name'))
                    ->formatStateUsing(fn (string $state): string => OrganizationOptions::roleLabel($state))->sortable(),
                IconColumn::make('system')->label(__('identity.role.system'))->boolean()
                    ->state(fn (Role $record): bool => $record->isSystemRole()),
                TextColumn::make('permissions_count')->label(__('identity.role.permissions'))->counts('permissions'),
            ])
            ->recordActions([
                EditAction::make()->after(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions()),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
