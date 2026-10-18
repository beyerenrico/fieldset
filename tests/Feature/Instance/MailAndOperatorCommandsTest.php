<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\IssueOwnerLink;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\LoginLinks\LoginLinkKind;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use App\Domain\Platform\PlatformAdmins;
use App\Filament\Admin\Pages\Auth\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;

/*
 * The app side of `fsa config set mail` and `fsa config set operator-email` (plan U12, KTD25; ADR 0080), as the
 * socket-less one-offs fsa runs: `instance:mail-test` sends the test mail synchronously through the instance mailer,
 * `instance:mail-captured` counts what capture mode kept from people, `instance:operator-email` grants the operator
 * role (creating the account and its first link when needed) and with --revoke-previous revokes the others.
 */

/** @return array{0: array<string, mixed>, 1: int} */
function mailCommandJson(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, [...$arguments, '--json' => true]);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));
    expect($lines)->toHaveCount(1);

    return [json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR), $exit];
}

/** @return list<SentMessage> */
function sentTestMails(): array
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return $transport instanceof ArrayTransport ? array_values($transport->messages()->all()) : [];
}

it('sends the test mail synchronously to the longest-standing active operator', function (): void {
    config(['mail.default' => 'array']);
    $first = User::factory()->create(['email' => 'first-op@example.com']);
    $second = User::factory()->create(['email' => 'second-op@example.com']);
    app(GrantPlatformAdmin::class)->handle($first);
    app(GrantPlatformAdmin::class)->handle($second);
    $first->forceFill(['status' => UserStatus::Deactivated])->saveQuietly();

    [$json, $exit] = mailCommandJson('instance:mail-test');

    $sent = sentTestMails();
    expect($exit)->toBe(0)
        ->and($json['data'])->toBe(['to' => 'second-op@example.com', 'mailer' => 'array'])
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe('second-op@example.com')
        ->and($sent[0]->toString())->toContain(__('capture.test_mail.subject'));
});

it('sends the test mail to --to', function (): void {
    config(['mail.default' => 'array']);

    [$json, $exit] = mailCommandJson('instance:mail-test', ['--to' => 'Ops@Example.com']);

    expect($exit)->toBe(0)->and($json['data']['to'])->toBe('ops@example.com')->and(sentTestMails())->toHaveCount(1);
});

it('reports a refused test mail without the SMTP credentials', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => [
        'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'relay-user-x', 'password' => 'relay-pass-x', 'timeout' => 2,
    ]]);
    Mail::purge('smtp');

    [$json, $exit] = mailCommandJson('instance:mail-test', ['--to' => 'ops@example.com']);

    expect($exit)->toBe(1)
        ->and($json['errors'][0]['code'])->toBe('test_mail_failed')
        ->and(json_encode($json))->not->toContain('relay-pass-x')->not->toContain('relay-user-x');
});

it('refuses a test mail without a recipient or with an invalid one', function (array $arguments): void {
    [$json, $exit] = mailCommandJson('instance:mail-test', $arguments);

    expect($exit)->toBe(1)->and($json['errors'][0]['code'])->toBe('invalid_input');
})->with([
    'nobody to send to' => [[]],
    'not an address' => [['--to' => 'ops']],
]);

it('counts the pending invitations and open password resets that capture mode kept', function (): void {
    $org = makeOrganization(name: 'Capture Org');
    $pending = memberOf($org, SystemRole::FieldWorker);
    $stale = memberOf($org, SystemRole::FieldWorker);
    Membership::query()->where('user_id', $pending->id)->update(['status' => MembershipStatus::Invited, 'invited_at' => now()->subDay()]);
    Membership::query()->where('user_id', $stale->id)->update(['status' => MembershipStatus::Invited, 'invited_at' => now()->subDays(8)]);
    DB::table('password_reset_tokens')->insert([
        ['email' => 'fresh@example.com', 'token' => 'x', 'created_at' => now()->subMinutes(5)],
        ['email' => 'old@example.com', 'token' => 'y', 'created_at' => now()->subHours(2)],
    ]);
    // The owner link fsa handed over itself is a reset token too, but no mail went out for it.
    app(IssueOwnerLink::class)->handle(User::factory()->create(['email' => 'linked-owner@example.com']), 501);

    [$json, $exit] = mailCommandJson('instance:mail-captured');

    expect($exit)->toBe(0)->and($json['data'])->toBe(['invitations' => 1, 'password_resets' => 1]);
});

it('creates a new operator without an organization and issues the first sign-in link under secrets', function (): void {
    clearAuditLog();

    [$json, $exit] = mailCommandJson('instance:operator-email', ['--email' => 'New-Ops@Example.com', '--actor-uid' => '1000']);

    $user = User::query()->where('email', 'new-ops@example.com')->sole();
    $audit = Activity::query()->where('event', 'instance.config_changed')->sole();
    expect($exit)->toBe(0)
        ->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toMatchArray(['email' => 'new-ops@example.com', 'created' => true, 'granted' => true, 'revoked' => [], 'password_set' => false])
        ->and($json['secrets'][0]['name'])->toBe('sign_in_link')
        ->and(json_encode($json['data']))->not->toContain('/login-link#')
        ->and(app(PlatformAdmins::class)->check($user))->toBeTrue()
        ->and($user->password_known)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(LoginLink::query()->where('user_id', $user->id)->sole()->kind)->toBe(LoginLinkKind::Owner)
        ->and($audit->actor_type)->toBe('cli')
        ->and($audit->properties['key'])->toBe('operator-email')
        ->and($audit->properties['to'])->toBe('new-ops@example.com')
        ->and($audit->properties['host_uid'])->toBe(1000)
        ->and(Activity::query()->where('event', 'platform.admin_granted')->count())->toBe(1);
});

it('grants an existing account with a password without a link, and answers a repeat with changed: false', function (): void {
    User::factory()->create(['email' => 'ops@example.com']);

    [$json, $exit] = mailCommandJson('instance:operator-email', ['--email' => 'ops@example.com']);
    [$again] = mailCommandJson('instance:operator-email', ['--email' => 'ops@example.com']);

    expect($exit)->toBe(0)
        ->and($json['data'])->toMatchArray(['created' => false, 'granted' => true, 'password_set' => true, 'link' => 'none', 'link_expires_at' => null])
        ->and($json['secrets'])->toBe([])
        ->and($again['changed'])->toBeFalse()
        ->and($again['data']['granted'])->toBeFalse();
});

it('keeps the delivered sign-in link on a repeat while it can still be used: no new link, changed: false', function (): void {
    [$first] = mailCommandJson('instance:operator-email', ['--email' => 'new-ops@example.com']);
    $user = User::query()->where('email', 'new-ops@example.com')->sole();
    $delivered = LoginLink::query()->where('user_id', $user->id)->sole();

    [$again, $exit] = mailCommandJson('instance:operator-email', ['--email' => 'new-ops@example.com']);

    expect($first['data'])->toMatchArray(['link' => 'issued', 'link_expires_at' => $first['secrets'][0]['expires_at']])
        ->and($exit)->toBe(0)
        ->and($again['changed'])->toBeFalse()
        ->and($again['secrets'])->toBe([])
        ->and($again['data'])->toMatchArray(['created' => false, 'granted' => false, 'password_set' => false, 'link' => 'outstanding', 'link_expires_at' => $first['secrets'][0]['expires_at']])
        ->and(LoginLink::query()->where('user_id', $user->id)->sole()->is($delivered))->toBeTrue();
});

it('issues a new sign-in link on a repeat once the earlier one expired unused', function (): void {
    mailCommandJson('instance:operator-email', ['--email' => 'new-ops@example.com']);
    $this->travel(61)->minutes();

    [$again, $exit] = mailCommandJson('instance:operator-email', ['--email' => 'new-ops@example.com']);

    expect($exit)->toBe(0, json_encode($again))
        ->and($again['changed'])->toBeTrue()
        ->and($again['data'])->toMatchArray(['created' => false, 'granted' => false, 'link' => 'issued'])
        ->and($again['secrets'][0]['name'])->toBe('sign_in_link');
});

it('revokes every other operator with --revoke-previous', function (): void {
    $old = User::factory()->create(['email' => 'old-op@example.com']);
    app(GrantPlatformAdmin::class)->handle($old);
    $new = User::factory()->create(['email' => 'new-op@example.com']);

    [$json, $exit] = mailCommandJson('instance:operator-email', ['--email' => 'new-op@example.com', '--revoke-previous' => true]);

    expect($exit)->toBe(0)
        ->and($json['data']['revoked'])->toBe(['old-op@example.com'])
        ->and(app(PlatformAdmins::class)->check($old))->toBeFalse()
        ->and(app(PlatformAdmins::class)->check($new))->toBeTrue()
        ->and(Activity::query()->where('event', 'instance.config_changed')->latest('id')->first()?->properties['revoked'])->toBe(['old-op@example.com']);
});

it('refuses an address whose domain enforces SSO before anything changes', function (): void {
    $this->mock(PasswordAuthGate::class, fn ($mock) => $mock->shouldReceive('assertPasswordLogin')->andThrow(new SsoRequiredException(new DiscoveryResult(null, null, true))));

    [$json, $exit] = mailCommandJson('instance:operator-email', ['--email' => 'ops@sso.example']);

    expect($exit)->toBe(1)->and($json['errors'][0]['code'])->toBe('sso_required')
        ->and(User::query()->where('email', 'ops@sso.example')->exists())->toBeFalse();
});

it('refuses an invalid address and a deactivated account', function (): void {
    [$invalid, $invalidExit] = mailCommandJson('instance:operator-email', ['--email' => 'ops']);
    $user = User::factory()->create(['email' => 'gone@example.com', 'status' => UserStatus::Deactivated]);
    [$inactive, $inactiveExit] = mailCommandJson('instance:operator-email', ['--email' => 'gone@example.com']);

    expect($invalidExit)->toBe(1)->and($invalid['errors'][0]['code'])->toBe('invalid_input')
        ->and($inactiveExit)->toBe(1)->and($inactive['errors'][0]['code'])->toBe('inactive')
        ->and(app(PlatformAdmins::class)->granted($user))->toBeFalse();
});

it('leads an operator without an organization from the first link to the /platform sign-in', function (): void {
    Filament::setCurrentPanel('admin');
    [$json] = mailCommandJson('instance:operator-email', ['--email' => 'solo-op@example.com']);
    $token = Str::after($json['secrets'][0]['value'], '#');

    $this->post('/login-link', ['token' => $token])->assertStatus(303);
    Livewire::test(ResetPassword::class, ['email' => 'solo-op@example.com'])
        ->assertSet('fromOwnerLink', true)
        ->fillForm(['password' => 'N3w-Operator-Passw0rd!', 'passwordConfirmation' => 'N3w-Operator-Passw0rd!'])
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertNotified(__('login_link.operator.ready_title'))
        ->assertRedirect(route('filament.platform.auth.login'));

    $this->assertGuest();
    expect(User::query()->where('email', 'solo-op@example.com')->sole()->password_known)->toBeTrue();
});
