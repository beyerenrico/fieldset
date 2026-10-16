<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Roles;

use App\Domain\Identity\Actions\DeleteRole;
use App\Domain\Identity\Actions\SaveRole;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
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

/** Custom roles as a checkbox list of capabilities (ADR 0017). Saves go through SaveRole. */
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
            CheckboxList::make('permission_ids')->label(__('identity.role.permissions'))
                ->options(fn (?Role $record): array => self::permissionOptions($record))
                ->columns(2)->bulkToggleable(),
        ]);
    }

    /**
     * Grantable permissions plus the ones the role already has (so they can be removed).
     *
     * @return array<int, string>
     */
    private static function permissionOptions(?Role $record): array
    {
        $options = OrganizationOptions::grantablePermissions();
        foreach ($record instanceof Role ? $record->permissions : [] as $permission) {
            $options[(int) $permission->id] ??= Permission::tryFrom($permission->name)?->label() ?? $permission->name;
        }
        ksort($options);

        return $options;
    }

    /** @param  array{name?: string, permission_ids?: list<int|string>}  $data */
    public static function save(?Role $record, array $data): Role
    {
        /** @var User $actor */
        $actor = auth()->user();
        /** @var Organization $organization */
        $organization = app(TenantContext::class)->organization();

        return app(SaveRole::class)->handle($actor, $organization, $record, $data['name'] ?? null, array_values($data['permission_ids'] ?? []));
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
                EditAction::make()
                    ->fillForm(fn (Role $record): array => [
                        'name' => $record->name,
                        'permission_ids' => $record->permissions->pluck('id')->all(),
                    ])
                    ->using(fn (Role $record, array $data): Role => self::save($record, $data)),
                DeleteAction::make()->using(function (Role $record): bool {
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(DeleteRole::class)->handle($actor, $record);

                    return true;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
