<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Tables;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation\ImpersonationRefused;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use App\Domain\Platform\Impersonation\StartImpersonation;
use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Component;

/**
 * Memberships of one organization (not a tenant-scoped model) with the "Impersonate" action. The action is
 * only offered for active admin-panel members who are not operators; StartImpersonation re-checks every rule.
 */
final class OrganizationMembersTable
{
    public static function configure(Table $table, Organization $organization): Table
    {
        /** @var list<int> $operators */
        $operators = PlatformAdmin::query()->pluck('user_id')->map(intval(...))->all();

        return $table
            ->query(Membership::query()->where('organization_id', $organization->id)->with('user'))
            ->defaultSort('joined_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label(__('platform.members.name'))->searchable()
                    ->formatStateUsing(fn (string $state): string => AccountScrubber::displayName($state)),
                TextColumn::make('user.email')->label(__('platform.members.email'))->searchable(),
                TextColumn::make('status')->label(__('platform.members.status'))->badge()
                    ->formatStateUsing(fn (MembershipStatus $state): string => __('identity.member.statuses.'.$state->value))
                    ->color(fn (MembershipStatus $state): string => $state === MembershipStatus::Active ? 'success' : 'gray'),
                TextColumn::make('operator')->label(__('platform.members.operator'))->badge()->color('warning')
                    ->state(fn (Membership $record): ?string => in_array($record->user_id, $operators, true) ? (string) __('platform.members.is_operator') : null),
                TextColumn::make('joined_at')->label(__('platform.members.joined'))->isoDateTime('L LT')
                    ->timezone(SubmissionResource::displayTimezone())->sortable(),
            ])
            ->recordActions([self::impersonateAction($organization, $operators)]);
    }

    /** @param  list<int>  $operators */
    private static function impersonateAction(Organization $organization, array $operators): Action
    {
        return Action::make('impersonate')
            ->label(__('platform.impersonation.action'))
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('warning')
            ->visible(fn (Membership $record): bool => self::offered($record, $organization, $operators))
            ->modalHeading(fn (Membership $record): string => __('platform.impersonation.modal_heading', ['name' => $record->user->name]))
            ->modalDescription(__('platform.impersonation.modal_help', ['minutes' => StartImpersonation::minutes()]))
            ->modalSubmitActionLabel(__('platform.impersonation.start'))
            ->schema([
                Textarea::make('reason')->label(__('platform.impersonation.reason'))
                    ->helperText(__('platform.impersonation.reason_help'))
                    ->required()->minLength(StartImpersonation::REASON_MIN)->maxLength(StartImpersonation::REASON_MAX)->rows(3),
            ])
            ->action(function (Membership $record, array $data, Component $livewire) use ($organization): void {
                $operator = Filament::auth()->user();
                if (! $operator instanceof User) {
                    abort(403);
                }

                try {
                    app(StartImpersonation::class)->handle($operator, $organization, $record->user, (string) ($data['reason'] ?? ''));
                } catch (ImpersonationRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();

                    return;
                }

                $livewire->redirect(url('/admin/'.$organization->slug));
            });
    }

    /** @param  list<int>  $operators */
    private static function offered(Membership $record, Organization $organization, array $operators): bool
    {
        return $record->status === MembershipStatus::Active
            && $record->user->isActive()
            && ! in_array($record->user_id, $operators, true)
            && ! $organization->trashed()
            && ! app(ImpersonationStore::class)->active()
            && $record->user->hasPermissionIn($organization, Permission::AdminAccess);
    }
}
