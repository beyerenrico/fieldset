<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Members\Pages;

use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Admin\Support\OrganizationOptions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('identity.member.invite'))
                ->icon('heroicon-o-envelope')
                ->authorize(fn (): bool => auth()->user()?->can('create', Membership::class) ?? false)
                ->schema([
                    TextInput::make('email')->label(__('identity.member.email'))->email()->required()->maxLength(255),
                    TextInput::make('name')->label(__('identity.member.name'))->required()->maxLength(120),
                    CheckboxList::make('roles')->label(__('identity.member.roles'))
                        ->options(fn (): array => OrganizationOptions::roles())->required(),
                    Select::make('groups')->label(__('identity.member.groups'))->multiple()
                        ->options(fn (): array => OrganizationOptions::groups()),
                ])
                ->action(function (array $data): void {
                    /** @var Organization $organization */
                    $organization = Filament::getTenant();
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(InviteMember::class)->handle(
                        $actor,
                        $organization,
                        $data['email'],
                        $data['name'],
                        array_values($data['roles']),
                        array_values(array_map(intval(...), $data['groups'] ?? [])),
                    );
                    Notification::make()->success()->title(__('identity.member.invited'))->send();
                }),
        ];
    }
}
