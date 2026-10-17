<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Login\SsoLoginResponder;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * The result of a test sign-in (SsoLoginResponder flashes `sso.test_result` when the IdP round trip returns
 * to the SSO page) as a persistent notification: who signed in and which groups arrived, or why it failed
 * with the raw detail below. Results for another provider (deleted or replaced meanwhile) are ignored.
 */
final class TestResultNotification
{
    public static function fromSession(?IdentityProvider $provider): ?Notification
    {
        $result = session(SsoLoginResponder::TEST_RESULT_FLASH);
        if (! is_array($result) || ! $provider instanceof IdentityProvider || ($result['provider'] ?? null) !== $provider->uuid) {
            return null;
        }

        return ($result['ok'] ?? false) === true ? self::succeeded($result) : self::failed($result);
    }

    /** @param  array<array-key, mixed>  $result */
    private static function succeeded(array $result): Notification
    {
        $profile = is_array($result['profile'] ?? null) ? $result['profile'] : [];
        $claims = is_array($profile['claims'] ?? null) ? $profile['claims'] : [];
        $groups = array_values(array_filter(array_map(
            static fn (mixed $group): string => is_scalar($group) ? (string) $group : '',
            is_array($claims['groups'] ?? null) ? $claims['groups'] : [$claims['groups'] ?? null],
        ), static fn (string $group): bool => $group !== ''));

        return Notification::make()->success()->persistent()
            ->title(__('sso_admin.test_result.succeeded', [
                'email' => self::text($profile['email'] ?? null) ?? __('sso_admin.test_result.no_email'),
                'groups' => $groups === [] ? __('sso_admin.test_result.no_groups') : implode(', ', $groups),
            ]))
            ->body(__('sso_admin.test_result.details', [
                'subject' => self::text($profile['subject'] ?? null) ?? '–',
                'issuer' => self::text($profile['issuer'] ?? null) ?? '–',
                'verified' => __(($profile['email_verified'] ?? false) === true ? 'sso_admin.test_result.yes' : 'sso_admin.test_result.no'),
            ]));
    }

    /** @param  array<array-key, mixed>  $result */
    private static function failed(array $result): Notification
    {
        $code = SsoRefusal::tryFrom(self::text($result['error'] ?? null) ?? '') ?? SsoRefusal::SsoFailed;
        $key = 'sso_admin.login.refused.'.$code->value;

        return Notification::make()->danger()->persistent()
            ->title(__('sso_admin.test_result.failed', ['reason' => __(Lang::has($key) ? $key : 'sso_admin.login.refused.sso_failed')]))
            ->body(self::text($result['detail'] ?? null));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 500) : null;
    }
}
