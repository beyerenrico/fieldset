<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * Status of the SSO page: provider status (in words, not by colour alone), last successful sign-in, last
 * error, and the URLs to register at the identity provider (copyable).
 */
final class StatusSchema
{
    /** @return list<Section> */
    public static function components(?IdentityProvider $provider): array
    {
        if (! $provider instanceof IdentityProvider) {
            return [];
        }

        $timezone = SubmissionResource::displayTimezone();

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
                        ->state($provider->last_error)->placeholder(__('sso_admin.status.no_error'))
                        ->helperText($provider->last_error_at?->timezone($timezone)->isoFormat('LLL'))->columnSpanFull(),
                    ...self::urls($provider),
                ])
                ->columns(2),
        ];
    }

    /** @return list<TextEntry> */
    private static function urls(IdentityProvider $provider): array
    {
        $entries = $provider->type === IdentityProviderType::Oidc
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
