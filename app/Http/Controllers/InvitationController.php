<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\AcceptInvitation;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use App\Domain\Identity\Sso\Login\SsoStartLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Signed invitation link (security review M0):
 * - new accounts (never verified) choose a password here and are signed in once;
 * - existing accounts must sign in normally (password + MFA) and then accept; an account the IdP just signed
 *   in counts as existing, so it accepts without a password;
 * - a link only works while the membership is still `invited` (410 afterwards);
 * - when the email's domain enforces SSO (ADR 0036) nobody sets or types a password here: the link sends a
 *   person who is not signed in as the invited account to the enforcing organization's IdP. For that organization's own invitation the SSO login accepts it; for
 *   another organization's, the link is remembered (url.intended), the flow carries the invitation (no JIT
 *   into the enforcing organization, see ProvisionMembership) and the IdP's return lands back here to accept.
 */
final readonly class InvitationController
{
    public function __construct(private PasswordAuthGate $gate, private SsoStartLink $links) {}

    public function show(Request $request, Membership $membership): View|RedirectResponse
    {
        abort_if($membership->status !== MembershipStatus::Invited, 410);

        $signedIn = $membership->user->is($request->user());
        $isNewAccount = $membership->user->email_verified_at === null && ! $signedIn;

        // New or existing account: under enforced SSO the person signs in at the IdP, straight from the link.
        if (! $signedIn && ($sso = $this->ssoRedirect($membership)) instanceof SsoRequiredException) {
            return redirect($this->ssoUrl($request, $membership, $sso));
        }

        if (! $isNewAccount && ! $signedIn) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('invitations.accept', [
            'membership' => $membership,
            'needsPassword' => $isNewAccount,
            'needsLogin' => ! $isNewAccount && ! $signedIn,
            'loginUrl' => $this->loginUrl($membership),
        ]);
    }

    public function accept(Request $request, Membership $membership, AcceptInvitation $accept, BlockedPasswordFlow $blocked): RedirectResponse
    {
        abort_if($membership->status !== MembershipStatus::Invited, 410);

        $isNewAccount = $membership->user->email_verified_at === null && ! $membership->user->is($request->user());

        if ($isNewAccount && ($sso = $this->ssoRedirect($membership)) instanceof SsoRequiredException) {
            $blocked->login($membership->user->email, $sso, 'invitation');

            return redirect($this->ssoUrl($request, $membership, $sso), 303);
        }

        $data = $request->validate($isNewAccount ? ['password' => ['required', 'confirmed', Password::defaults()]] : []);

        if (! $isNewAccount) {
            abort_unless($membership->user->is($request->user()), 403);
        }

        DB::transaction(function () use ($membership, $data, $accept): void {
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== MembershipStatus::Invited, 410);
            $accept->handle($locked, $data['password'] ?? null);
        });

        if ($isNewAccount) {
            Auth::login($membership->user);
            $request->session()->regenerate();
        }

        return redirect($this->home($membership));
    }

    /** The gate's refusal when a password may not be set for this email (enforced SSO), else null. */
    private function ssoRedirect(Membership $membership): ?SsoRequiredException
    {
        try {
            $this->gate->assertPasswordLogin($membership->user->email);
        } catch (SsoRequiredException $e) {
            return $e;
        }

        return null;
    }

    /** A start link of the enforcing IdP; another organization's invitation rides along and is returned to. */
    private function ssoUrl(Request $request, Membership $membership, SsoRequiredException $sso): string
    {
        $provider = $sso->discovery->provider;
        if (! $provider instanceof IdentityProvider) {
            return $this->loginUrl($membership);
        }

        $client = $membership->user->hasPermissionIn($membership->organization_id, Permission::AdminAccess) ? SsoClient::Admin : SsoClient::App;
        $elsewhere = $provider->organization_id !== $membership->organization_id;
        if ($elsewhere) {
            // The signed link itself (the accept form posts to the same URL), so the return shows it again.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return $this->links->url($provider, $client, $membership->user->email, $elsewhere ? $membership->id : null);
    }

    private function home(Membership $membership): string
    {
        return $membership->user->hasPermissionIn($membership->organization_id, Permission::AdminAccess)
            ? url('/admin/'.$membership->organization->slug)
            : url('/app');
    }

    private function loginUrl(Membership $membership): string
    {
        return $membership->user->hasPermissionIn($membership->organization_id, Permission::AdminAccess)
            ? url('/admin/login')
            : url('/app/login');
    }
}
