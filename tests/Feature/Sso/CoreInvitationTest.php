<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, finding 5: organization A enforces SSO for acme.example; organization B invites
 * new@acme.example. The invitation sends the person to A's IdP and back to the invitation, which they then
 * accept as an existing account — without silently joining A (ADR 0036 JIT still applies to A's own logins).
 */
beforeEach(function (): void {
    Notification::fake();
    $this->idp = FakeOidcIdp::bind();
    $this->enforcing = makeOrganization(name: 'Acme GmbH');
    FakeOidcIdp::domain($this->enforcing, 'acme.example');
    $this->provider = $this->idp->provider($this->enforcing, ['enforce_sso' => true, 'break_glass_user_id' => ownerOf($this->enforcing)->id]);
    $this->inviting = makeOrganization(name: 'Partner GmbH');
    $this->membership = app(InviteMember::class)->handle(ownerOf($this->inviting), $this->inviting, 'new@acme.example', 'New Person', ['field_worker']);
    $this->url = URL::temporarySignedRoute('invitations.show', now()->addDay(), ['membership' => $this->membership->id]);
});

/** The invitation link → A's IdP → the callback; returns the callback response. */
function coreInvitationViaIdp(object $test): TestResponse
{
    $start = (string) $test->get($test->url)->assertRedirect()->headers->get('Location');
    expect($start)->toStartWith($test->provider->redirectUrl(SsoClient::App, 'new@acme.example').'&start=');
    $authorization = (string) $test->get($start)->assertRedirect()->headers->get('Location');

    return $test->get($test->idp->authorize($authorization, ['sub' => 'kc-new', 'email' => 'new@acme.example']));
}

it('returns from the enforcing IdP to the invitation, which the signed-in account accepts without a password', function (): void {
    coreInvitationViaIdp($this)->assertRedirect($this->url);

    $user = User::query()->where('email', 'new@acme.example')->sole();
    expect(auth()->id())->toBe($user->id)
        ->and($user->email_verified_at)->not->toBeNull();

    $passwordHash = $user->password;
    $this->get($this->url)->assertOk()->assertViewHas('needsPassword', false)->assertViewHas('needsLogin', false);
    $this->post($this->url)->assertRedirect(url('/app'));

    expect($this->membership->fresh()->status)->toBe(MembershipStatus::Active)
        ->and(Activity::query()->where('event', 'member.joined')->sole()->properties['via'])->toBe('invitation')
        ->and($user->fresh()->password)->toBe($passwordHash);
});

it('does not join the enforcing organization by JIT on the way, and audits why', function (): void {
    coreInvitationViaIdp($this)->assertRedirect($this->url);

    $user = User::query()->where('email', 'new@acme.example')->sole();
    expect(Membership::query()->where('organization_id', $this->enforcing->id)->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'sso.user_provisioned')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'sso.jit_skipped')->sole()->properties->all())
        ->toMatchArray(['reason' => 'invitation', 'invitation_organization_id' => $this->inviting->id, 'provider' => $this->provider->uuid]);

    // A later ordinary login through A's IdP follows ADR 0036 JIT.
    auth()->logout();
    $this->idp->login($this->provider, ['sub' => 'kc-new', 'email' => 'new@acme.example'])->assertRedirect('/app/login?sso=ok');
    expect(Membership::query()->where('organization_id', $this->enforcing->id)->where('user_id', $user->id)->sole()->status)->toBe(MembershipStatus::Active);
});

it('lets the invitation through even when the enforcing organization has JIT off', function (): void {
    app(TenantContext::class)->run($this->enforcing, fn () => $this->provider->forceFill(['jit_enabled' => false])->save());

    coreInvitationViaIdp($this)->assertRedirect($this->url);
    $this->post($this->url)->assertRedirect(url('/app'));

    expect($this->membership->fresh()->status)->toBe(MembershipStatus::Active);
});

it('sends an existing account of the enforced domain to the IdP too, carrying the invitation', function (): void {
    $existing = User::factory()->create(['email' => 'old@acme.example']);
    $membership = app(InviteMember::class)->handle(ownerOf($this->inviting), $this->inviting, 'old@acme.example', 'Old Account', ['field_worker']);
    $url = URL::temporarySignedRoute('invitations.show', now()->addDay(), ['membership' => $membership->id]);

    $start = (string) $this->get($url)->assertRedirect()->headers->get('Location');
    expect($start)->toStartWith($this->provider->redirectUrl(SsoClient::App, 'old@acme.example').'&start=');
    $authorization = (string) $this->get($start)->assertRedirect()->headers->get('Location');
    $this->get($this->idp->authorize($authorization, ['sub' => 'kc-old', 'email' => 'old@acme.example']))->assertRedirect($url);

    expect(auth()->id())->toBe($existing->id)
        ->and(Membership::query()->where('organization_id', $this->enforcing->id)->where('user_id', $existing->id)->exists())->toBeFalse();
    $this->post($url)->assertRedirect(url('/app'));
    expect($membership->fresh()->status)->toBe(MembershipStatus::Active);
});
