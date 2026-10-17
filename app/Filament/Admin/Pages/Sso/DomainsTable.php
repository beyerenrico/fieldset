<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Domains\AddSsoDomain;
use App\Domain\Identity\Sso\Domains\RemoveSsoDomain;
use App\Domain\Identity\Sso\Domains\VerifySsoDomain;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Domains table of the SSO page: add a domain, show its TXT record (copyable), verify, remove. */
final class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SsoDomain::query())
            ->defaultSort('domain')
            ->paginated(false)
            ->heading(__('sso_admin.domains.title'))
            ->description(__('sso_admin.domains.help'))
            ->emptyStateHeading(__('sso_admin.domains.empty'))
            ->emptyStateDescription(__('sso_admin.domains.empty_help'))
            ->columns([
                TextColumn::make('domain')->label(__('sso_admin.domains.domain'))->fontFamily('mono'),
                TextColumn::make('state')->label(__('sso_admin.domains.status'))->badge()
                    ->state(fn (SsoDomain $record): string => __($record->isVerified() ? 'sso_admin.domains.verified' : 'sso_admin.domains.pending'))
                    ->color(fn (SsoDomain $record): string => $record->isVerified() ? 'success' : 'warning'),
                TextColumn::make('last_checked_at')->label(__('sso_admin.domains.last_checked'))->since()
                    ->timezone(SubmissionResource::displayTimezone())->placeholder(__('sso_admin.domains.never_checked'))
                    ->description(fn (SsoDomain $record): ?string => $record->last_check_result instanceof DomainVerification
                        ? (string) __('sso_admin.domains.results.'.$record->last_check_result->value) : null),
            ])
            ->headerActions([self::add()])
            ->recordActions([self::instructions(), self::verify(), self::remove()]);
    }

    private static function add(): Action
    {
        return Action::make('addDomain')
            ->label(__('sso_admin.domains.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => self::user()?->can('create', SsoDomain::class) ?? false)
            ->schema([
                TextInput::make('domain')->label(__('sso_admin.domains.domain'))->required()->maxLength(253)
                    ->placeholder('example.com')->helperText(__('sso_admin.domains.add_help')),
            ])
            ->action(function (array $data, Schema $schema): void {
                $organization = Filament::getTenant();
                $user = self::user();
                abort_unless($organization instanceof Organization && $user instanceof User, 403);
                try {
                    $domain = app(AddSsoDomain::class)->handle($organization, (string) $data['domain'], $user);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages([$schema->getStatePath().'.domain' => $e->validator->errors()->all()]);
                }
                Notification::make()->success()->title(__('sso_admin.domains.added', ['domain' => $domain->domain]))
                    ->body(__('sso_admin.domains.added_help'))->send();
            });
    }

    private static function instructions(): Action
    {
        return Action::make('dnsRecord')
            ->label(__('sso_admin.domains.dns_record'))
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('gray')
            ->modalHeading(fn (SsoDomain $record): string => __('sso_admin.domains.dns_heading', ['domain' => $record->domain]))
            ->modalDescription(__('sso_admin.domains.dns_help'))
            ->schema([
                TextInput::make('txt_name')->label(__('sso_admin.domains.txt_name'))->readOnly()->copyable(copyMessage: __('sso_admin.status.copied')),
                TextInput::make('txt_type')->label(__('sso_admin.domains.txt_type'))->readOnly(),
                TextInput::make('txt_value')->label(__('sso_admin.domains.txt_value'))->readOnly()->copyable(copyMessage: __('sso_admin.status.copied')),
                Text::make(__('sso_admin.domains.dns_note')),
            ])
            ->fillForm(fn (SsoDomain $record): array => ['txt_name' => $record->txtName(), 'txt_type' => 'TXT', 'txt_value' => $record->txtValue()])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('sso_admin.domains.close'));
    }

    private static function verify(): Action
    {
        return Action::make('verifyDomain')
            ->label(fn (SsoDomain $record): string => __($record->isVerified() ? 'sso_admin.domains.check_again' : 'sso_admin.domains.verify'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->authorize(fn (SsoDomain $record): bool => self::user()?->can('update', $record) ?? false)
            ->action(function (SsoDomain $record): void {
                try {
                    $result = app(VerifySsoDomain::class)->handle($record, self::user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->validator->errors()->first())->send();

                    return;
                }
                $notification = Notification::make()->title(__('sso_admin.domains.results.'.$result->value));
                if ($result === DomainVerification::Verified) {
                    $notification->success()->send();

                    return;
                }
                $notification->warning()->body(__('sso_admin.domains.not_verified_help', ['name' => $record->txtName()]))->send();
            });
    }

    private static function remove(): Action
    {
        return Action::make('removeDomain')
            ->label(__('sso_admin.domains.remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(fn (SsoDomain $record): string => __('sso_admin.domains.remove_confirm', ['domain' => $record->domain]))
            ->authorize(fn (SsoDomain $record): bool => self::user()?->can('delete', $record) ?? false)
            ->action(function (SsoDomain $record): void {
                try {
                    app(RemoveSsoDomain::class)->handle($record, self::user() ?? abort(403));
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->validator->errors()->first())->send();

                    return;
                }
                Notification::make()->success()->title(__('sso_admin.domains.removed', ['domain' => $record->domain]))->send();
            });
    }

    private static function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
