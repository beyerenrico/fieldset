<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Privacy;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Erasure\EraseUser;
use App\Domain\Privacy\Exports\RequestDataExport;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
 * ZIP, link by notification) or erase their account. Owners and Admins (members.manage).
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
            ->query(fn (): Builder => Membership::query()->with('user')->where('organization_id', $organization?->getKey()))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('user.name')->label(__('privacy.members.columns.name'))->searchable(),
                TextColumn::make('user.email')->label(__('privacy.members.columns.email'))->searchable(),
                TextColumn::make('status')->label(__('privacy.members.columns.status'))->badge()
                    ->formatStateUsing(fn (MembershipStatus $state): string => (string) __('privacy.members.status.'.$state->value)),
            ])
            ->recordActions([$this->exportAction(), $this->eraseAction()]);
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
            ->modalHeading(__('privacy.members.erase_heading'))
            ->modalDescription(__('privacy.members.erase_description'))
            ->schema([
                TextInput::make('confirmation')->label(__('privacy.members.erase_confirm'))->required()
                    ->helperText(fn (Membership $record): string => (string) __('privacy.members.erase_confirm_help', ['email' => $record->user->email])),
            ])
            ->action(function (Membership $record, array $data): void {
                if (mb_strtolower(trim((string) $data['confirmation'])) !== mb_strtolower($record->user->email)) {
                    Notification::make()->warning()->title(__('privacy.members.erase_mismatch'))->send();

                    return;
                }
                PrivacyPage::attempt(fn () => app(EraseUser::class)->byAdmin(PrivacyPage::user(), $record->user), __('privacy.members.erased'));
            });
    }
}
