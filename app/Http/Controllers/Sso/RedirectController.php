<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Login\DomainProviderLookup;
use App\Domain\Identity\Sso\Login\FinishSsoLogin;
use App\Domain\Identity\Sso\Login\SsoFlow;
use App\Domain\Identity\Sso\Login\SsoFlowStore;
use App\Domain\Identity\Sso\Login\SsoLoginResponder;
use App\Domain\Identity\Sso\Login\SsoStartLink;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET /auth/sso/{provider}/redirect?client=admin|app&start=…[&login_hint=…] or ?client=admin&test=1
 * (ADR 0036): starts an OIDC (state, nonce, PKCE S256, response_mode=query; prompt=login with force_reauth
 * or in test mode) or SAML login (RelayState = one-time flow id bound to the AuthnRequest id). The return
 * target is the client enum. A login needs the `start` token a CSRF-protected step of this session issued
 * (SsoStartLink: discovery, the password gate, an invitation) — a cross-site link cannot start one (login
 * CSRF). Test mode needs members.manage in the provider's organization instead, works for draft providers,
 * signs nobody in and returns the claims to the admin page that started it.
 */
final class RedirectController
{
    public function __invoke(
        Request $request,
        IdentityProvider $provider,
        DynamicProviderFactory $factory,
        SsoFlowStore $flows,
        SsoStartLink $links,
        FinishSsoLogin $finish,
        SsoLoginResponder $respond,
    ): Response {
        $client = SsoClient::tryFrom((string) $request->query('client', SsoClient::App->value));
        abort_if($client === null, 404);
        $test = $request->boolean('test');
        $invitation = null;

        if ($test) {
            $this->authorizeTest($request, $provider);
            abort_if($provider->status === IdentityProviderStatus::Disabled, 409);
        } elseif (! DomainProviderLookup::takesLogins($provider)) {
            return $respond->failure($client, SsoRefusal::ProviderUnavailable);
        } else {
            $start = $links->redeem($request, $provider, $client);
            if ($start === null) {
                return $finish->flowRejected($client, 'start_missing', $provider);
            }
            $invitation = $start['invitation'];
        }

        $flow = $flows->start($request, $provider, $client, $test, $test ? $this->adminReturnUrl() : null, $invitation);

        try {
            return $provider->type === IdentityProviderType::Oidc
                ? $this->oidc($request, $factory, $flows, $provider, $flow, $this->loginHint($request))
                : $this->saml($factory, $flows, $provider, $flow);
        } catch (Throwable $e) {
            $flows->forget($request, $flow);

            return $finish->failed($provider, $flow, $e);
        }
    }

    private function oidc(Request $request, DynamicProviderFactory $factory, SsoFlowStore $flows, IdentityProvider $provider, SsoFlow $flow, ?string $loginHint): Response
    {
        $response = $factory->oidc($provider)->with(array_filter([
            'response_mode' => 'query',
            'login_hint' => $loginHint,
            'prompt' => $provider->force_reauth || $flow->test ? 'login' : null,
        ]))->redirect();
        $flows->bindOidc($request, $flow);

        return $response;
    }

    private function saml(DynamicProviderFactory $factory, SsoFlowStore $flows, IdentityProvider $provider, SsoFlow $flow): Response
    {
        $saml = $factory->saml($provider)->forcingAuthentication($provider->force_reauth || $flow->test);
        $saml->with(['RelayState' => $flow->id]);
        $response = $saml->redirect();
        $flows->rememberRelay($flow, (string) $saml->lastRequestId());

        return $response;
    }

    private function authorizeTest(Request $request, IdentityProvider $provider): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermissionIn($provider->organization_id, Permission::MembersManage), 403);
    }

    /** The admin page that started the test (same origin, under /admin), else the panel. */
    private function adminReturnUrl(): string
    {
        $previous = url()->previous();

        return str_starts_with($previous, url('/admin').'/') ? $previous : url('/admin');
    }

    private function loginHint(Request $request): ?string
    {
        $hint = $request->query('login_hint');

        return is_string($hint) && filter_var($hint, FILTER_VALIDATE_EMAIL) !== false && strlen($hint) <= 254 ? $hint : null;
    }
}
