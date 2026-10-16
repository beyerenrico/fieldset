<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
});

function inviteFlow(string $email, array $roles = ['designer']): Membership
{
    return app(InviteMember::class)->handle(test()->owner, test()->org, $email, 'Invited Person', $roles);
}

function invitationLink(Membership $membership): string
{
    return URL::temporarySignedRoute('invitations.show', now()->addDay(), ['membership' => $membership->id]);
}

it('lets a new account set a password and signs it in exactly once', function (): void {
    $membership = inviteFlow('new@example.com');
    $url = invitationLink($membership);

    $this->post($url, ['password' => 'Sup3r-Secret-Pw!', 'password_confirmation' => 'Sup3r-Secret-Pw!'])->assertRedirect();
    expect(auth()->id())->toBe($membership->user_id)
        ->and($membership->fresh()->status)->toBe(MembershipStatus::Active);

    auth()->logout();
    $this->post($url, ['password' => 'Other-Secret-Pw!', 'password_confirmation' => 'Other-Secret-Pw!'])->assertStatus(410);
    $this->get($url)->assertStatus(410);
    $this->assertGuest();
});

it('requires an existing account to sign in normally before accepting', function (): void {
    $existing = User::factory()->create(['email' => 'known@example.com']);
    $membership = inviteFlow('known@example.com');
    $url = invitationLink($membership);

    $this->get($url)->assertOk()->assertSee(__('identity.invitation.login_first'));
    $this->post($url)->assertForbidden();
    expect($membership->fresh()->status)->toBe(MembershipStatus::Invited);

    $this->actingAs($existing)->post($url)->assertRedirect(url('/admin/'.$this->org->slug));
    expect($membership->fresh()->status)->toBe(MembershipStatus::Active);
});

it('does not log anyone in through a link of a deactivated membership', function (): void {
    $membership = inviteFlow('gone@example.com');
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, $this->owner);

    $this->post(invitationLink($membership), ['password' => 'Sup3r-Secret-Pw!', 'password_confirmation' => 'Sup3r-Secret-Pw!'])->assertStatus(410);
    $this->assertGuest();
});

it('rejects unsigned links', function (): void {
    $membership = inviteFlow('x@example.com');

    $this->get(route('invitations.show', ['membership' => $membership->id]))->assertForbidden();
});

it('refuses to re-invite an active member', function (): void {
    $member = memberOf($this->org);

    expect(fn (): Membership => inviteFlow($member->email))->toThrow(ValidationException::class);
});

it('requires email-change verification in the admin profile', function (): void {
    expect(Filament::getPanel('admin')->hasEmailChangeVerification())->toBeTrue();
});

it('does not let the Field App profile endpoint change the email address', function (): void {
    $user = User::factory()->create(['email' => 'mine@example.com']);

    $this->actingAs($user)->putJson('/auth/user/profile-information', ['name' => 'New Name', 'email' => 'cfo@victim.example'])->assertOk();

    expect($user->fresh())->email->toBe('mine@example.com')->name->toBe('New Name');
});
