<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;
use App\Domain\Identity\Sso\Login\FinishSsoLogin;
use App\Domain\Identity\Sso\Login\SamlProfileFactory;
use App\Domain\Identity\Sso\Login\SsoFlowStore;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use App\Domain\Identity\Sso\Providers\SamlSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SocialiteProviders\Saml2\User as SamlUser;
use Throwable;
use UnexpectedValueException;

/**
 * POST /auth/sso/{provider}/saml/acs — without session, shared errors or CSRF (ADR 0036, SAML): a cross-site
 * POST carries no Lax cookie and must not start or overwrite a session. The RelayState must be a live
 * one-time flow id of this provider; the response is validated statelessly against that flow's AuthnRequest
 * id; the result (profile or refusal) travels in an encrypted one-time ticket with a 303 to
 * sso.saml.complete, where the session cookie is present again. Provider faults are recorded here.
 */
final class SamlAcsController
{
    public function __invoke(
        Request $request,
        IdentityProvider $provider,
        SsoFlowStore $flows,
        DynamicProviderFactory $factory,
        SamlProfileFactory $profiles,
        FinishSsoLogin $finish,
        AuditLogger $audit,
    ): RedirectResponse {
        $relayState = $request->request->all()['RelayState'] ?? null;
        $relay = $flows->pullRelay(is_string($relayState) ? $relayState : '');
        if ($relay === null || ! hash_equals($relay['provider'], $provider->uuid) || $provider->type !== IdentityProviderType::Saml) {
            $audit->record('auth.sso_failed', $provider, ['provider' => $provider->uuid, 'reason' => SsoRefusal::SsoFailed->value, 'detail' => 'saml_relay_state']);

            return redirect()->route('sso.saml.complete', status: 303);
        }

        try {
            $user = $factory->saml($provider)->expectingResponseTo($relay['request_id'])->stateless()->user();
            if (! $user instanceof SamlUser) {
                throw new UnexpectedValueException('SAML: the provider returned no SAML user.');
            }
            $result = $profiles->make($provider, SamlSettings::of($provider), $user);
        } catch (SsoLoginRefused $e) {
            $finish->recordRefusal($provider, $e->reason);
            $result = $e->reason;
        } catch (Throwable $e) {
            $result = $finish->recordFailure($provider, $e)->code;
        }

        return redirect()->route('sso.saml.complete', ['ticket' => $flows->issueTicket($relay['flow'], $provider->uuid, $result)], 303);
    }
}
