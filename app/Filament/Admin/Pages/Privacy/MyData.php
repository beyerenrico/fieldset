<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Privacy;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Enums\DataExportStatus;
use App\Domain\Privacy\Erasure\EraseUser;
use App\Domain\Privacy\Exports\DataExportNotifications;
use App\Domain\Privacy\Exports\RequestDataExport;
use App\Domain\Privacy\Models\DataExport;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
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
 * Self-service privacy (GDPR Art. 15/17/20, ADR 0039): export my personal data (queued ZIP, link by
 * notification and in the list below, which also shows my audit log exports) and delete my account
 * (password confirmation; refused while I am the last Owner of an organization).
 */
class MyData extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 94;

    protected static ?string $slug = 'privacy/my-data';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isActive() && Filament::getTenant() instanceof Organization;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('privacy.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy.my_data.navigation');
    }

    public function getTitle(): string
    {
        return __('privacy.my_data.title');
    }

    public function getSubheading(): string
    {
        return __('privacy.my_data.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportMyData')->label(__('privacy.my_data.export'))->icon(Heroicon::OutlinedArrowDownTray)
                ->requiresConfirmation()->modalDescription(__('privacy.my_data.export_description'))
                ->action(fn (): bool => PrivacyPage::attempt(
                    fn () => app(RequestDataExport::class)->userData(PrivacyPage::user(), PrivacyPage::user(), app()->getLocale(), SubmissionResource::displayTimezone()),
                    __('privacy.exports.queued'),
                )),
            Action::make('deleteAccount')->label(__('privacy.my_data.delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                ->modalHeading(__('privacy.my_data.delete_heading'))->modalDescription(__('privacy.my_data.delete_description'))
                ->schema([TextInput::make('password')->label(__('privacy.my_data.password'))->password()->required()->currentPassword()])
                ->action(function (): void {
                    $user = PrivacyPage::user();
                    if (PrivacyPage::attempt(fn () => app(EraseUser::class)->self($user), __('privacy.my_data.deleted'))) {
                        Filament::auth()->logout();
                        session()->invalidate();
                        session()->regenerateToken();
                        $this->redirect(Filament::getLoginUrl() ?? '/');
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('privacy.my_data.exports'))
            ->query(fn (): Builder => DataExport::query()->where('requested_by', Filament::auth()->id()))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('kind')->label(__('privacy.exports.columns.kind'))->formatStateUsing(fn (DataExport $record): string => (string) __('privacy.exports.kinds.'.$record->kind->value)),
                TextColumn::make('status')->label(__('privacy.exports.columns.status'))->badge()->formatStateUsing(fn (DataExportStatus $state): string => $state->label()),
                TextColumn::make('created_at')->label(__('privacy.exports.columns.requested_at'))->dateTime()->timezone(SubmissionResource::displayTimezone()),
                TextColumn::make('expires_at')->label(__('privacy.exports.columns.expires_at'))->dateTime()->timezone(SubmissionResource::displayTimezone())->placeholder('—'),
            ])
            ->recordActions([
                Action::make('download')->label(__('privacy.notifications.download'))->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (DataExport $record): bool => $record->isReady())
                    ->url(fn (DataExport $record): ?string => DataExportNotifications::downloadUrl($record)),
            ])
            ->emptyStateHeading(__('privacy.my_data.no_exports'));
    }
}
