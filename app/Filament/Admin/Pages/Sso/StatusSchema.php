<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Lang;

/**
 * Status of the SSO page: provider status (in words, not by colour alone), last successful sign-in, last
 * error (one translated sentence per reason, the raw detail below it), what the test sign-in does, and the
 * URLs to register at the identity provider (copyable). Before the first save the URLs of the pending
 * provider id are shown for the protocol chosen in the form.
 */
final class StatusSchema
{
    /**
     * @param  \Closure(): string  $selectedType  the protocol currently chosen in the provider form
     * @return list<Section>
     */
    public static function components(?IdentityProvider $provider, ?IdentityProvider $pending, \Closure $selectedType): array
    {
        if (! $provider instanceof IdentityProvider) {
            return $pending instanceof IdentityProvider ? [self::pendingAddresses($pending, $selectedType)] : [];
        }

        $timezone = SubmissionResource::displayTimezone();
        [$errorText, $errorDetail] = self::lastError($provider->last_error);

        return [
            Section::make(__('sso_admin.status.title'))
                ->description(__('sso_admin.status.help'))
                ->schema([
                    TextEntry::make('sso_status')->label(__('sso_admin.status.status'))
                        ->state(__('sso_admin.status.states.'.$provider->status->value))->badge()
                        ->color(self::color($provider->status)),
                    TextEntry::make('sso_last_success')->label(__('sso_admin.status.last_success'))
                        ->state($provider->last_success_at)->dateTime()->timezone($timezone)->placeholder(__('sso_admin.status.never')),
                    TextEntry::make('sso_last_error')->label(__('sso_admin.status.last_error'))
                        ->state($errorText)->placeholder(__('sso_admin.status.no_error'))
                        ->hint($provider->last_error_at?->timezone($timezone)->isoFormat('LLL'))
                        ->helperText($errorDetail)->columnSpanFull(),
                    Text::make(__('sso_admin.actions.test_login_help'))->columnSpanFull(),
                    ...self::urls($provider, $provider->type),
                ])
                ->columns(2),
        ];
    }

    /**
     * The stored `last_error` is "<reason>: <detail>" (ProviderStatusRecorder): a translated sentence for
     * the reason and the raw detail.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function lastError(?string $lastError): array
    {
        if (blank($lastError)) {
            return [null, null];
        }
        $key = preg_match('/^([a-z][a-z0-9_]{0,63}): ?(.*)$/s', $lastError, $match) === 1
            ? 'sso_admin.status.error_reasons.'.$match[1] : null;
        if ($key === null || ! Lang::has($key)) {
            return [(string) __('sso_admin.status.error_reasons.other'), $lastError];
        }

        return [(string) __($key), $match[2] === '' ? null : $match[2]];
    }

    /** @param  \Closure(): string  $selectedType */
    private static function pendingAddresses(IdentityProvider $pending, \Closure $selectedType): Section
    {
        $components = [];
        foreach ([IdentityProviderType::Oidc, IdentityProviderType::Saml] as $type) {
            foreach (self::urls($pending, $type) as $entry) {
                $components[] = $entry->visible(static fn (): bool => $selectedType() === $type->value);
            }
        }

        return Section::make(__('sso_admin.status.addresses_title'))
            ->description(__('sso_admin.status.addresses_help'))
            ->schema($components);
    }

    /** @return list<TextEntry> */
    private static function urls(IdentityProvider $provider, IdentityProviderType $type): array
    {
        $entries = $type === IdentityProviderType::Oidc
            ? ['sso_callback_url' => ['callback_url', $provider->callbackUrl()]]
            : ['sso_acs_url' => ['acs_url', $provider->samlAcsUrl()], 'sso_metadata_url' => ['sp_metadata_url', $provider->samlEntityId()]];

        $components = [];
        foreach ($entries as $name => [$label, $url]) {
            $components[] = TextEntry::make($name)->label(__('sso_admin.status.'.$label))->state($url)
                ->copyable()->copyMessage(__('sso_admin.status.copied'))->fontFamily('mono')->columnSpanFull();
        }

        return $components;
    }

    private static function color(IdentityProviderStatus $status): string
    {
        return match ($status) {
            IdentityProviderStatus::Active => 'success',
            IdentityProviderStatus::Error => 'danger',
            IdentityProviderStatus::Draft => 'warning',
            IdentityProviderStatus::Disabled => 'gray',
        };
    }
}
