<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\ApiTokens;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tokens\Actions\RevokeApiToken;
use App\Domain\Identity\Tokens\ApiTokenAccess;
use App\Domain\Identity\Tokens\TokenAbility;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token list of the API tokens page (ADR 0043): the user's own tokens in this organization, or every token
 * of the organization for `members.manage`. Never shows a token value; revoking is the only record action.
 */
final class ApiTokensTable
{
    public static function configure(Table $table, User $user, Organization $organization): Table
    {
        $manageAll = app(ApiTokenAccess::class)->canManageAll($user, $organization);

        return $table
            ->query(fn (): Builder => self::query($user, $organization, $manageAll))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('api_tokens.table.empty'))
            ->emptyStateDescription(__('api_tokens.table.empty_description'))
            ->columns([
                TextColumn::make('name')->label(__('api_tokens.table.name'))->searchable()->weight('medium'),
                TextColumn::make('owner')->label(__('api_tokens.table.owner'))->visible($manageAll)
                    ->state(fn (PersonalAccessToken $record): string => self::ownerName($record)),
                TextColumn::make('abilities')->label(__('api_tokens.table.abilities'))->badge()->color('gray')
                    ->formatStateUsing(fn (mixed $state): string => TokenAbility::tryFrom((string) $state)?->label() ?? (string) $state),
                TextColumn::make('last_used_at')->label(__('api_tokens.table.last_used'))->since()
                    ->timezone(SubmissionResource::displayTimezone())->placeholder(__('api_tokens.table.never_used')),
                TextColumn::make('expires_at')->label(__('api_tokens.table.expires'))->date()
                    ->timezone(SubmissionResource::displayTimezone())->placeholder(__('api_tokens.table.never_expires'))
                    ->color(fn (PersonalAccessToken $record): ?string => self::expired($record) ? 'danger' : null)
                    ->description(fn (PersonalAccessToken $record): ?string => self::expired($record) ? (string) __('api_tokens.table.expired') : null),
                TextColumn::make('created_at')->label(__('api_tokens.table.created'))->date()
                    ->timezone(SubmissionResource::displayTimezone())->sortable(),
            ])
            ->recordActions([self::revoke($user)]);
    }

    /** @return Builder<PersonalAccessToken> */
    private static function query(User $user, Organization $organization, bool $manageAll): Builder
    {
        return PersonalAccessToken::query()
            ->where('organization_id', $organization->id)
            ->when(! $manageAll, fn (Builder $query) => $query
                ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id))
            ->with('tokenable');
    }

    private static function revoke(User $user): Action
    {
        return Action::make('revoke')
            ->label(__('api_tokens.revoke.label'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (PersonalAccessToken $record): string => __('api_tokens.revoke.heading', ['name' => $record->name]))
            ->modalDescription(__('api_tokens.revoke.description'))
            ->authorize(fn (PersonalAccessToken $record): bool => app(ApiTokenAccess::class)->canRevoke($user, $record))
            ->action(function (PersonalAccessToken $record) use ($user): void {
                app(RevokeApiToken::class)->handle($record, $user);
                Notification::make()->success()->title(__('api_tokens.revoke.done'))->send();
            });
    }

    private static function ownerName(PersonalAccessToken $token): string
    {
        $owner = $token->tokenable;

        return $owner instanceof User ? $owner->name : '';
    }

    private static function expired(PersonalAccessToken $token): bool
    {
        return $token->expires_at !== null && $token->expires_at->isPast();
    }
}
