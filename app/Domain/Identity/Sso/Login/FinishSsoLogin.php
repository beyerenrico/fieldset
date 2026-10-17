<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Enums\SsoRefusal;
use App\Domain\Identity\Sso\Exceptions\SsoLoginRefused;
use App\Domain\Identity\Sso\Providers\FailureClassifier;
use App\Domain\Identity\Sso\Providers\SsoFailure;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The common end of the OIDC callback and the SAML completion (ADR 0036): complete the login and sign in,
 * answer test mode, or record a failure (auth.sso_failed / auth.sso_refused) and send the client to its error
 * page. Only provider faults proven over the back channel set status=error and reach the
 * SsoMisconfigurationReporter (FailureClassifier); input the browser brought — the OIDC `error` redirect, a
 * posted SAML response — is audited with fixed codes (`cause`, `detail`) and never with its own text.
 */
final readonly class FinishSsoLogin
{
    public function __construct(
        private CompleteSsoLogin $complete,
        private SsoLoginResponder $respond,
        private ProviderStatusRecorder $status,
        private FailureClassifier $classifier,
        private SsoMisconfigurationReporter $reporter,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    public function complete(Request $request, IdentityProvider $provider, SsoFlow $flow, ExternalProfile $profile): RedirectResponse
    {
        if ($flow->test) {
            $this->status->tested($provider);
            $this->audit->record('sso.provider_tested', $provider, ['ok' => true, 'provider' => $provider->uuid], $request->user());

            return $this->respond->tested($flow, $profile);
        }

        try {
            $user = $this->complete->handle($provider, $profile, $flow->invitation);
        } catch (SsoLoginRefused $e) {
            return $this->refused($provider, $flow, $e->reason, $profile);
        }

        LoginAuditContext::sso($provider, $profile);
        Auth::login($user, remember: $flow->client === SsoClient::App);
        $request->session()->regenerate();
        $this->status->succeeded($provider);

        return $this->respond->success($flow->client, $user);
    }

    public function refused(IdentityProvider $provider, SsoFlow $flow, SsoRefusal $reason, ?ExternalProfile $profile = null): RedirectResponse
    {
        $this->recordRefusal($provider, $reason, $profile);

        return $this->failureResponse($flow, $reason);
    }

    public function failed(IdentityProvider $provider, SsoFlow $flow, Throwable $e): RedirectResponse
    {
        $failure = $this->recordFailure($provider, $e);

        return $this->failureResponse($flow, $failure->code, $failure->detail);
    }

    /**
     * The IdP's error redirect (`?error=…` on the OIDC callback of a live flow): front-channel input anyone
     * can send, so only an allow-listed error code reaches the audit and nothing counts as a provider fault.
     */
    public function idpError(IdentityProvider $provider, SsoFlow $flow, string $error): RedirectResponse
    {
        $code = $error === 'access_denied' ? SsoRefusal::SsoDenied : SsoRefusal::SsoFailed;
        $cause = FailureClassifier::idpErrorCause($error);
        $this->audit->record('auth.sso_failed', $provider, [
            'provider' => $provider->uuid, 'reason' => $code->value, 'misconfiguration' => null, 'cause' => $cause, 'detail' => $cause,
        ]);

        return $this->failureResponse($flow, $code, $cause);
    }

    public function recordRefusal(IdentityProvider $provider, SsoRefusal $reason, ?ExternalProfile $profile = null): void
    {
        $this->audit->record('auth.sso_refused', $provider, [
            'provider' => $provider->uuid, 'reason' => $reason->value, 'email' => $profile?->email, 'subject' => $profile?->subject,
            'detail' => RefusalDetail::pull(),
        ]);
    }

    /**
     * Classifies and audits a failure; provider faults set status=error and notify (ADR 0036).
     *
     * @param  bool  $frontChannel  the failure came out of a response the browser posted (SAML ACS)
     */
    public function recordFailure(IdentityProvider $provider, Throwable $e, bool $frontChannel = false): SsoFailure
    {
        $failure = $this->classifier->classify($e, $provider->type, $frontChannel);
        $this->audit->record('auth.sso_failed', $provider, [
            'provider' => $provider->uuid, 'reason' => $failure->code->value,
            'misconfiguration' => $failure->misconfiguration, 'cause' => $failure->cause, 'detail' => $failure->detail,
        ]);

        if ($failure->misconfiguration !== null) {
            $reason = $failure->misconfiguration;
            $this->status->failed($provider, $reason, $failure->detail);
            $this->tenant->run($provider->organization_id, fn () => $this->reporter->report($provider, $reason, $failure->detail));
        }

        return $failure;
    }

    /** A request without a matching flow: missing, expired, replayed or from another session. */
    public function flowRejected(SsoClient $client, string $why, ?IdentityProvider $provider = null): RedirectResponse
    {
        $this->audit->record('auth.sso_failed', $provider, ['provider' => $provider?->uuid, 'reason' => SsoRefusal::SsoFailed->value, 'detail' => $why]);

        return $this->respond->failure($client, SsoRefusal::SsoFailed);
    }

    public function failureResponse(SsoFlow $flow, SsoRefusal $code, ?string $detail = null): RedirectResponse
    {
        return $flow->test ? $this->respond->testFailed($flow, $code, $detail) : $this->respond->failure($flow->client, $code);
    }
}
