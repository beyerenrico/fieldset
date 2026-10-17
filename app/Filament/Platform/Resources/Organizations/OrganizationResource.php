<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\PlatformAdmins;
use App\Filament\Platform\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Platform\Resources\Organizations\Pages\OrganizationMembers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only list of every organization for instance operators (§8.12, ADR 0041). Organizations and
 * memberships are not tenant-scoped models; the panel has no tenancy. Access is the operator check, not
 * OrganizationPolicy (that one answers for members of an organization).
 */
final class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'organizations';

    public static function getModelLabel(): string
    {
        return __('platform.organizations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.organizations.plural');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(PlatformAdmins::class)->check($user);
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return Builder<Organization> */
    public static function getEloquentQuery(): Builder
    {
        return Organization::query()->withTrashed()->withCount([
            'memberships as active_members_count' => fn (Builder $query): Builder => $query->where('status', MembershipStatus::Active->value),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('platform.organizations.name'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('platform.organizations.slug'))->searchable(),
                TextColumn::make('active_members_count')->label(__('platform.organizations.members'))->numeric()->sortable(),
                TextColumn::make('created_at')->label(__('platform.organizations.created'))->dateTime()->sortable(),
                TextColumn::make('status')->label(__('platform.organizations.status'))->badge()
                    ->state(fn (Organization $record): string => $record->trashed() ? 'archived' : 'active')
                    ->formatStateUsing(fn (string $state): string => __('platform.organizations.statuses.'.$state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordUrl(fn (Organization $record): string => self::getUrl('members', ['record' => $record]))
            ->recordActions([
                Action::make('members')->label(__('platform.members.action'))->icon(Heroicon::OutlinedUsers)
                    ->url(fn (Organization $record): string => self::getUrl('members', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'members' => OrganizationMembers::route('/{record}/members'),
        ];
    }
}
