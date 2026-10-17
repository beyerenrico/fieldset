<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * Where an SSO login lands (ADR 0036, M7 contract):
 * - app: `/app/login?sso=ok`, failures `/app/login?sso_error=<code>`;
 * - an invitation the person came from (url.intended under /invitations/, set by the invitation link): back to
 *   it for either client, to accept it now that the IdP has signed them in;
 * - admin: the intended Filament URL (or `/admin`), `/app/login?sso=ok` for a user without panel access;
 *   failures go to the Filament login `?sso_error=<code>`, also flashed as `sso_error` (text: `sso.errors.<code>`);
 * - test mode: back to the admin page that started it with the flash `sso.test_result`.
 */
final class SsoLoginResponder
{
    public const string ERROR_FLASH = 'sso_error';

    public const string TEST_RESULT_FLASH = 'sso.test_result';

    public function success(SsoClient $client, User $user): RedirectResponse
    {
        $intended = session('url.intended');
        if (is_string($intended) && str_starts_with($intended, url('/invitations').'/')) {
            return redirect()->intended(url('/app/login?'.http_build_query(['sso' => 'ok'])));
        }

        if ($client === SsoClient::Admin && $user->canAccessPanel(Filament::getPanel('admin'))) {
            return redirect()->intended(url('/admin'));
        }

        return redirect(url('/app/login?'.http_build_query(['sso' => 'ok'])));
    }

    public function failure(SsoClient $client, SsoRefusal $code): RedirectResponse
    {
        if ($client === SsoClient::App) {
            return redirect(url('/app/login?'.http_build_query(['sso_error' => $code->value])));
        }

        $query = ['sso_error' => $code->value];

        return redirect(Filament::getPanel('admin')->getLoginUrl($query) ?? url('/admin/login?'.http_build_query($query)))
            ->with(self::ERROR_FLASH, $code->value);
    }

    public function tested(SsoFlow $flow, ExternalProfile $profile): RedirectResponse
    {
        return redirect($flow->returnUrl ?? url('/admin'))->with(self::TEST_RESULT_FLASH, [
            'ok' => true,
            'provider' => $flow->providerUuid,
            'profile' => ProfileArray::from($profile),
        ]);
    }

    public function testFailed(SsoFlow $flow, SsoRefusal $code, ?string $detail): RedirectResponse
    {
        return redirect($flow->returnUrl ?? url('/admin'))->with(self::TEST_RESULT_FLASH, [
            'ok' => false,
            'provider' => $flow->providerUuid,
            'error' => $code->value,
            'detail' => $detail,
        ]);
    }
}
