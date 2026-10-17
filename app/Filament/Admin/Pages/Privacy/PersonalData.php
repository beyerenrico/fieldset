<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Privacy;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Domain\Privacy\Erasure\EraseUser;
use App\Domain\Privacy\Exports\RequestDataExport;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data subject requests for members (GDPR Art. 15/17/20, ADR 0039): export a member's personal data (queued
 * ZIP, link by notification) or erase their account (typing the email address; errors stay at the field).
 * Erase is disabled, with the next step as tooltip, for accounts shared with another organization and for
 * platform operators. Owners and Admins (members.manage).
 */
class PersonalData extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'privacy/personal-data';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Organization && $user->isActive() && $user->hasPermissionIn($tenant, Permission::MembersManage);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('privacy.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy.members.navigation');
    }

    public function getTitle(): string
    {
        return __('privacy.members.title');
    }

    public function getSubheading(): string
    {
        return __('privacy.members.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $organization = Filament::getTenant();

        return $table
            ->query(fn (): Builder => $this->memberships($organization?->getKey()))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('user.name')->label(__('privacy.members.columns.name'))->searchable()
                    ->formatStateUsing(fn (string $state): string => AccountScrubber::displayName($state)),
                TextColumn::make('user.email')->label(__('privacy.members.columns.email'))->searchable(),
                TextColumn::make('status')->label(__('privacy.members.columns.status'))->badge()
                    ->formatStateUsing(fn (MembershipStatus $state): string => (string) __('privacy.members.status.'.$state->value)),
            ])
            ->recordActions([$this->exportAction(), $this->eraseAction()]);
    }

    /** @return Builder<Membership> with why Erase is unavailable, without a query per row (EraseUser refuses both anyway) */
    private function memberships(mixed $organizationId): Builder
    {
        return Membership::query()->with('user')->where('organization_user.organization_id', $organizationId)->select('organization_user.*')
            ->selectRaw('EXISTS (SELECT 1 FROM organization_user AS other WHERE other.user_id = organization_user.user_id AND other.organization_id <> organization_user.organization_id) AS belongs_elsewhere')
            ->selectRaw('EXISTS (SELECT 1 FROM platform_admins WHERE platform_admins.user_id = organization_user.user_id) AS platform_operator');
    }

    private function exportAction(): Action
    {
        return Action::make('exportData')->label(__('privacy.members.export'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->requiresConfirmation()->modalDescription(__('privacy.members.export_description'))
            ->action(fn (Membership $record): bool => PrivacyPage::attempt(
                fn () => app(RequestDataExport::class)->userData(PrivacyPage::user(), $record->user, app()->getLocale(), SubmissionResource::displayTimezone()),
                __('privacy.exports.queued'),
            ));
    }

    private function eraseAction(): Action
    {
        return Action::make('erase')->label(__('privacy.members.erase'))->icon(Heroicon::OutlinedTrash)->color('danger')
            ->hidden(fn (Membership $record): bool => $record->user_id === Filament::auth()->id() || EraseUser::isErased($record->user))
            ->disabled(fn (Membership $record): bool => $this->unavailableBecause($record) !== null)
            ->tooltip(fn (Membership $record): ?string => $this->unavailableBecause($record))
            ->modalHeading(__('privacy.members.erase_heading'))
            ->modalDescription(__('privacy.members.erase_description'))
            ->schema([
                TextInput::make('confirmation')->label(__('privacy.members.erase_confirm'))->required()
                    ->helperText(fn (Membership $record): string => (string) __('privacy.members.erase_confirm_help', ['email' => $record->user->email]))
                    ->rule(fn (Membership $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if (! EraseUser::emailConfirms($record->user, $value)) {
                            $fail(__('privacy.members.erase_mismatch'));
                        }
                    }),
            ])
            ->action(fn (Membership $record, Schema $schema): bool => PrivacyPage::attemptInForm(
                fn () => app(EraseUser::class)->byAdmin(PrivacyPage::user(), $record->user),
                __('privacy.members.erased'),
                $schema,
                ['confirmation'],
            ));
    }

    /** The next step when an admin may not erase the account here (shared with another organization, an operator). */
    private function unavailableBecause(Membership $record): ?string
    {
        return match (true) {
            (bool) $record->getAttribute('platform_operator') => (string) __('privacy.erasure.platform_operator'),
            (bool) $record->getAttribute('belongs_elsewhere') => (string) __('privacy.erasure.other_organizations'),
            default => null,
        };
    }
}
