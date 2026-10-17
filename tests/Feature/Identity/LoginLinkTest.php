<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLabels;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\IssueLoginLink;
use App\Domain\Identity\Actions\IssueOwnerLink;
use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\LoginLinks\ConsumeLoginLink;
use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\LoginLinks\LoginLinkHandoff;
use App\Domain\Identity\LoginLinks\LoginLinkRefused;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Platform\Auth\PlatformSession;
use App\Filament\Admin\Pages\Auth\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/../Platform/Support.php';

/*
 * One-time sign-in links for existing admins (BUILD_PROMPT_V2 R28, KTD21, ADR 0056): issuing (hashed, 15 minutes,
 * rate-limited, newest wins, audited as `cli`), the fragment landing page and its states, and the hand-off to an
 * ordinary /admin session (MFA challenge when enrolled, never /platform).
 */

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->org = makeOrganization();
    $this->admin = memberOf($this->org, SystemRole::Admin);
});

function loginLinkIssue(User $user, ?int $hostUid = 1000): IssuedLoginLink
{
    return app(IssueLoginLink::class)->handle($user, $hostUid);
}

function loginLinkToken(IssuedLoginLink $issued): string
{
    return Str::after($issued->url, '#');
}

it('issues a 15-minute link whose token travels in the fragment and is stored only as a hash', function (): void {
    $this->freezeTime();
    $issued = loginLinkIssue($this->admin);
    $token = loginLinkToken($issued);

    expect($issued->url)->toBe(url('/login-link').'#'.$token)
        ->and(parse_url($issued->url, PHP_URL_QUERY))->toBeNull()
        ->and($token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and($issued->link->expires_at->getTimestamp())->toBe(now()->addMinutes(15)->getTimestamp())
        ->and(LoginLink::query()->sole()->token_hash)->toBe(hash('sha256', $token))
        ->and(DB::table('login_links')->get()->toJson())->not->toContain($token)
        ->and(print_r($issued, true))->not->toContain($token);
});

it('audits issuing with actor "cli" and the host UID', function (): void {
    clearAuditLog();
    $token = loginLinkToken(loginLinkIssue($this->admin, 1000));

    $entry = Activity::query()->where('event', 'login_link.issued')->sole();
    app()->setLocale('en');
    expect($entry->actor_type)->toBe('cli')
        ->and($entry->causer_id)->toBeNull()
        ->and((int) $entry->subject_id)->toBe($this->admin->id)
        ->and($entry->properties['host_uid'])->toBe(1000)
        ->and($entry->properties['kind'])->toBe('admin')
        ->and($entry->properties['email'])->toBe($this->admin->email)
        ->and(AuditLabels::actor($entry))->toBe('Command line (host UID 1000)')
        ->and(AuditLabels::event('login_link.issued'))->toBe('One-time sign-in link issued')
        ->and(Activity::query()->get()->toJson())->not->toContain($token);
    app()->setLocale('de');
    expect(AuditLabels::actor($entry))->toBe('Kommandozeile (Host-UID 1000)');
});

it('lets a newer link invalidate the older one for the same user', function (): void {
    $first = loginLinkToken(loginLinkIssue($this->admin));
    $second = loginLinkToken(loginLinkIssue($this->admin));

    expect(LoginLink::query()->count())->toBe(1);
    $this->post('/login-link', ['token' => $first])->assertNotFound()->assertSee(__('login_link.states.invalid.heading'));
    $this->assertGuest();
    $this->post('/login-link', ['token' => $second])->assertRedirect(url('/admin'));
    $this->assertAuthenticatedAs($this->admin);
});

it('rate-limits issuing per account', function (): void {
    foreach (range(1, IssueLoginLink::MAX_PER_WINDOW) as $ignored) {
        loginLinkIssue($this->admin);
    }

    expect(fn () => loginLinkIssue($this->admin))->toThrow(fn (LoginLinkRefused $e) => expect($e->reason)->toBe('rate_limited'));
});

it('refuses accounts that are not active admins', function (): void {
    $worker = memberOf($this->org, SystemRole::FieldWorker);
    $inactive = memberOf($this->org, SystemRole::Admin);
    $inactive->forceFill(['status' => 'deactivated'])->save();

    expect(fn () => loginLinkIssue($worker))->toThrow(fn (LoginLinkRefused $e) => expect($e->reason)->toBe('not_admin'))
        ->and(fn () => loginLinkIssue($inactive))->toThrow(fn (LoginLinkRefused $e) => expect($e->reason)->toBe('inactive'))
        ->and(LoginLink::query()->count())->toBe(0);
});

it('serves a landing page that never sees the token and POSTs the fragment with a nonce script', function (): void {
    $response = $this->get('/login-link')->assertOk()
        ->assertSee(__('login_link.states.valid.heading'))
        ->assertSee('action="'.route('login-link.consume').'"', false)
        ->assertSee('window.location.hash', false);
    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);
    preg_match_all('/<script\b([^>]*)>/', (string) $response->getContent(), $scripts);

    expect($nonce[1] ?? '')->not->toBe('')
        ->and($scripts[1])->toHaveCount(1)
        ->and($scripts[1][0])->toContain('nonce="'.($nonce[1] ?? '-').'"');
    $this->assertGuest();
});

it('consumes the token only on POST: an admin without MFA gets an ordinary /admin session, recorded with time and IP', function (): void {
    $this->freezeTime();
    $token = loginLinkToken(loginLinkIssue($this->admin));
    $this->get('/login-link?token='.$token)->assertOk(); // even a token in the query string is ignored by the GET
    $this->assertGuest();
    clearAuditLog();

    $this->post('/login-link', ['token' => $token], ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(303)->assertRedirect(url('/admin'));

    $link = LoginLink::query()->sole();
    $this->assertAuthenticatedAs($this->admin);
    expect($link->used_at?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($link->used_ip)->toBe('203.0.113.7')
        ->and(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['login_link.used', 'auth.login'])
        ->and(Activity::query()->where('event', 'auth.login')->sole()->properties['method'])->toBe('login_link')
        ->and(Activity::query()->where('event', 'login_link.used')->sole()->ip_address)->toBe('203.0.113.7')
        ->and(app(PlatformSession::class)->isValid($this->admin))->toBeFalse();
});

it('shows the used, expired, invalid and rate-limited states in German and English with the recovery command', function (string $locale): void {
    app()->setLocale($locale);
    $this->withHeader('Accept-Language', $locale);
    $used = loginLinkToken(loginLinkIssue($this->admin));
    $this->post('/login-link', ['token' => $used]);
    auth()->logout();

    $this->post('/login-link', ['token' => $used])->assertStatus(410)
        ->assertSee(trans('login_link.states.used.heading', [], $locale))->assertSee('fsa login-link &lt;email&gt;', false);

    $expired = loginLinkToken(loginLinkIssue(memberOf($this->org, SystemRole::Admin)));
    $this->travel(16)->minutes();
    $this->post('/login-link', ['token' => $expired])->assertStatus(410)
        ->assertSee(trans('login_link.states.expired.heading', [], $locale))->assertSee('fsa login-link');

    $this->post('/login-link', ['token' => Str::random(64)])->assertNotFound()
        ->assertSee(trans('login_link.states.invalid.heading', [], $locale))->assertSee('fsa login-link');

    foreach (range(1, ConsumeLoginLink::MAX_ATTEMPTS_PER_MINUTE) as $ignored) {
        $this->post('/login-link', ['token' => 'x']);
    }
    $this->post('/login-link', ['token' => $expired])->assertStatus(429)->assertHeader('Retry-After')
        ->assertSee(trans('login_link.states.rate_limited.heading', [], $locale))->assertSee('fsa login-link');
    $this->assertGuest();
})->with(['de', 'en']);

it('has distinct texts for every state in both languages', function (): void {
    $states = ['valid', 'used', 'expired', 'rate_limited', 'invalid', 'sso_required'];
    foreach (['de', 'en'] as $locale) {
        $headings = array_map(fn (string $state): string => trans("login_link.states.{$state}.heading", [], $locale), $states);
        expect(array_unique($headings))->toHaveCount(count($states));
    }
    expect(trans('login_link.states.used.heading', [], 'de'))->not->toBe(trans('login_link.states.used.heading', [], 'en'));
});

it('still requires the MFA code for an admin with MFA enrolled, and never opens /platform', function (): void {
    $operator = platformOperatorGrant($this->admin); // operator + confirmed TOTP, but no /platform sign-in
    $secret = (string) $operator->getAppAuthenticationSecret();
    $token = loginLinkToken(loginLinkIssue($operator));

    $this->post('/login-link', ['token' => $token])->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest();

    $page = Livewire::test(Login::class)
        ->assertSet('loginLinkChallenge', true)
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null)
        ->set('data.multiFactor.app.code', '000000')->call('authenticate')
        ->assertHasErrors();
    $this->assertGuest();
    expect(Activity::query()->where('event', 'auth.mfa_failed')->sole()->properties['via'])->toBe('login_link');

    $page->set('data.multiFactor.app.code', (new Google2FA)->getCurrentOtp($secret))->call('authenticate')->assertHasNoErrors();
    $this->assertAuthenticatedAs($operator);
    expect(session()->has(LoginLinkHandoff::MFA_KEY))->toBeFalse()
        ->and(app(PlatformSession::class)->isValid($operator))->toBeFalse();
    $this->get('/platform/organizations')->assertRedirect('/platform/login');
});

it('drops the MFA hand-off when it is older than its window', function (): void {
    $mfa = SharedAppAuthentication::make()->recoverable();
    $mfa->saveSecret($this->admin, $mfa->generateSecret());
    $this->post('/login-link', ['token' => loginLinkToken(loginLinkIssue($this->admin))]);

    $this->travel(LoginLinkHandoff::MINUTES + 1)->minutes();

    Livewire::test(Login::class)->assertSet('loginLinkChallenge', false)->assertSet('userUndertakingMultiFactorAuthentication', null);
    $this->assertGuest();
});

it('refuses a link for a user whose domain enforces SSO, through the existing gate', function (): void {
    $this->admin->forceFill(['email' => 'boss@acme.example'])->save();
    $token = loginLinkToken(loginLinkIssue($this->admin)); // issued before enforcement

    loginLinkEnforceSso($this->org);

    expect(fn () => loginLinkIssue($this->admin))->toThrow(fn (LoginLinkRefused $e) => expect($e->reason)->toBe('sso_required'));
    $this->post('/login-link', ['token' => $token])->assertForbidden()->assertSee(__('login_link.states.sso_required.heading'));
    $this->assertGuest();
    expect(Activity::query()->where('event', 'auth.password_login_blocked')->pluck('properties')->pluck('via')->all())->toBe(['login_link', 'login_link'])
        ->and(LoginLink::query()->sole()->used_at)->toBeNull();
});

it('refuses the owner link (reset broker) for a domain that enforces SSO', function (): void {
    $this->admin->forceFill(['email' => 'boss@acme.example'])->save();
    $token = Str::after(app(IssueOwnerLink::class)->handle($this->admin)->url, '#');

    loginLinkEnforceSso($this->org);

    expect(fn () => app(IssueOwnerLink::class)->handle($this->admin))->toThrow(fn (LoginLinkRefused $e) => expect($e->reason)->toBe('sso_required'));
    $this->post('/login-link', ['token' => $token])->assertForbidden();
    $this->assertGuest();
    expect(Activity::query()->where('event', 'auth.password_reset_blocked')->pluck('properties')->pluck('via')->all())->toBe(['login_link', 'login_link'])
        ->and(session()->has(LoginLinkHandoff::RESET_KEY))->toBeFalse();
});

/** Verifies acme.example for the organization and makes its provider enforce SSO (the owner is the break-glass user). */
function loginLinkEnforceSso(Organization $organization): void
{
    $tenant = app(TenantContext::class);
    $tenant->run($organization, function () use ($organization): void {
        SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
        IdentityProvider::factory()->enforcing(ownerOf($organization))->create();
    });
    $tenant->clear();
}
