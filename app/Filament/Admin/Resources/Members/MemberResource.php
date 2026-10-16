<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Members;

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Membership;
use App\Filament\Admin\Resources\Members\Pages\ManageMembers;
use App\Filament\Admin\Support\OrganizationOptions;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MemberResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation.team');
    }

    public static function getModelLabel(): string
    {
        return __('identity.member.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('identity.member.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label(__('identity.member.name'))->searchable()->sortable(),
                TextColumn::make('user.email')->label(__('identity.member.email'))->searchable(),
                TextColumn::make('roles')->label(__('identity.member.roles'))->badge()
                    ->state(fn (Membership $record): array => array_map(
                        OrganizationOptions::roleLabel(...),
                        OrganizationOptions::rolesOf($record->user),
                    )),
                TextColumn::make('status')->label(__('identity.member.status'))->badge()
                    ->formatStateUsing(fn (MembershipStatus $state): string => __('identity.member.statuses.'.$state->value))
                    ->color(fn (MembershipStatus $state): string => match ($state) {
                        MembershipStatus::Active => 'success',
                        MembershipStatus::Invited => 'warning',
                        MembershipStatus::Deactivated => 'gray',
                    }),
                TextColumn::make('joined_at')->label(__('identity.member.joined_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('identity.member.status'))
                    ->options(collect(MembershipStatus::cases())->mapWithKeys(
                        fn (MembershipStatus $s): array => [$s->value => __('identity.member.statuses.'.$s->value)],
                    )->all()),
            ])
            ->recordActions([self::editAction(), self::statusAction()]);
    }

    private static function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('filament-actions::edit.single.label'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn (Membership $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->fillForm(fn (Membership $record): array => [
                'roles' => OrganizationOptions::rolesOf($record->user),
                'groups' => OrganizationOptions::groupIdsOf($record->user),
                'default_group_id' => $record->default_group_id,
            ])
            ->schema([
                CheckboxList::make('roles')->label(__('identity.member.roles'))
                    ->options(fn (): array => OrganizationOptions::roles())->required(),
                Select::make('groups')->label(__('identity.member.groups'))->multiple()
                    ->options(fn (): array => OrganizationOptions::groups()),
                Select::make('default_group_id')->label(__('identity.member.default_group'))
                    ->options(fn (): array => OrganizationOptions::groups()),
            ])
            ->action(function (Membership $record, array $data): void {
                app(AddMember::class)->handle($record->organization, $record->user, array_values($data['roles']), $record->status);
                app(SyncMemberGroups::class)->handle($record->organization, $record->user, array_values(array_map('intval', $data['groups'] ?? [])));
                $record->update(['default_group_id' => $data['default_group_id'] ?? null]);
            });
    }

    private static function statusAction(): Action
    {
        return Action::make('toggleStatus')
            ->label(fn (Membership $record): string => $record->status === MembershipStatus::Deactivated
                ? __('identity.member.reactivate') : __('identity.member.deactivate'))
            ->color(fn (Membership $record): string => $record->status === MembershipStatus::Deactivated ? 'success' : 'danger')
            ->requiresConfirmation()
            ->authorize(fn (Membership $record): bool => auth()->user()?->can('deactivate', $record) ?? false)
            ->action(function (Membership $record): void {
                try {
                    app(SetMembershipStatus::class)->handle($record, $record->status === MembershipStatus::Deactivated
                        ? MembershipStatus::Active : MembershipStatus::Deactivated);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembers::route('/')];
    }
}
