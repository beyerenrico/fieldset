<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;
use App\Domain\Identity\Sso\Login\FinishSsoLogin;
use App\Domain\Identity\Sso\Login\OidcProfileFactory;
use App\Domain\Identity\Sso\Login\SsoFlow;
use App\Domain\Identity\Sso\Login\SsoFlowStore;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use App\Domain\Identity\Sso\Providers\OidcSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /auth/sso/{provider}/callback (OIDC redirect URI, response_mode=query; ADR 0036): the session's flow is
 * pulled first (one use), then the package exchanges the code (PKCE verifier) and validates state, nonce and
 * the id_token; flavor rules and identity resolution follow in OidcProfileFactory and FinishSsoLogin.
 */
final class CallbackController
{
    public function __invoke(
        Request $request,
        IdentityProvider $provider,
        SsoFlowStore $flows,
        DynamicProviderFactory $factory,
        OidcProfileFactory $profiles,
        FinishSsoLogin $finish,
    ): RedirectResponse {
        $flow = $flows->pull($request);
        if (! $flow instanceof SsoFlow || ! $flow->belongsTo($provider) || $provider->type !== IdentityProviderType::Oidc) {
            return $finish->flowRejected($flow->client ?? SsoClient::App, 'oidc_flow_missing', $provider);
        }

        try {
            $user = $factory->oidc($provider)->user();
            $profile = $profiles->make($provider, OidcSettings::of($provider), $user);
        } catch (SsoLoginRefused $e) {
            return $finish->refused($provider, $flow, $e->reason);
        } catch (Throwable $e) {
            return $finish->failed($provider, $flow, $e);
        }

        return $finish->complete($request, $provider, $flow, $profile);
    }
}
