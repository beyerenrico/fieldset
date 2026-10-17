<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Pages\Auth\RequestPasswordReset;
use App\Filament\Admin\Pages\Auth\ResetPassword;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** Discovery double: SSO for one domain, password mode otherwise; counts calls. */
function adminLoginFakeDiscovery(?IdentityProvider $provider = null, string $domain = 'acme.example'): object
{
    $fake = new class($provider, $domain) implements HomeRealmDiscovery
    {
        /** @var list<array{string, SsoClient}> */
        public array $calls = [];

        public function __construct(private readonly ?IdentityProvider $provider, private readonly string $domain) {}

        public function discover(string $email, SsoClient $client): DiscoveryResult
        {
            $this->calls[] = [$email, $client];

            return $this->provider instanceof IdentityProvider && str_ends_with($email, '@'.$this->domain)
                ? new DiscoveryResult($this->provider, $this->provider->redirectUrl($client, $email), false)
                : DiscoveryResult::password();
        }
    };
    app()->instance(HomeRealmDiscovery::class, $fake);

    return $fake;
}

/** Gate double: refuses password flows for one domain; records the checked emails. */
function adminLoginFakeGate(?IdentityProvider $provider, string $domain = 'acme.example'): object
{
    $fake = new class($provider, $domain) implements PasswordAuthGate
    {
        /** @var list<string> */
        public array $checked = [];

        public function __construct(private readonly ?IdentityProvider $provider, private readonly string $domain) {}

        public function assertPasswordLogin(string $email): void
        {
            $this->assert($email);
        }

        public function assertPasswordReset(string $email): void
        {
            $this->assert($email);
        }

        public function isBreakGlass(User $user): bool
        {
            return false;
        }

        private function assert(string $email): void
        {
            $this->checked[] = $email;
            if ($this->provider instanceof IdentityProvider && str_ends_with($email, '@'.$this->domain)) {
                throw new SsoRequiredException(new DiscoveryResult($this->provider, $this->provider->redirectUrl(SsoClient::App, $email), true));
            }
        }
    };
    app()->instance(PasswordAuthGate::class, $fake);

    return $fake;
}

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->org = makeOrganization();
    $this->provider = app(TenantContext::class)->run($this->org, fn (): IdentityProvider => IdentityProvider::factory()->active()->create());
    app(TenantContext::class)->clear();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    $this->designer->forceFill(['email' => 'designer@demo.test', 'password' => 'password'])->save();
});

it('shows the email step first', function (): void {
    $this->get('/admin/login')->assertOk()
        ->assertSee(__('sso_admin.login.continue'))
        ->assertSee(__('sso_admin.login.use_password'))
        ->assertDontSee('current-password');
});

it('redirects to the identity provider when discovery finds SSO', function (): void {
    $discovery = adminLoginFakeDiscovery($this->provider);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'boss@acme.example'])
        ->call('continueWithEmail')
        ->assertRedirect($this->provider->redirectUrl(SsoClient::Admin, 'boss@acme.example'));

    expect($discovery->calls)->toBe([['boss@acme.example', SsoClient::Admin]]);
    $this->assertGuest();
});

it('continues to the password step and signs in with Filament\'s flow', function (): void {
    adminLoginFakeDiscovery($this->provider);
    $gate = adminLoginFakeGate($this->provider);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'designer@demo.test'])
        ->call('continueWithEmail')
        ->assertSet('step', Login::STEP_PASSWORD)
        ->assertSee('current-password')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->designer);
    expect($gate->checked)->toContain('designer@demo.test');
});

it('submitting the email step never checks a password', function (): void {
    adminLoginFakeDiscovery();
    $gate = adminLoginFakeGate(null);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'designer@demo.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertSet('step', Login::STEP_PASSWORD);

    $this->assertGuest();
    expect($gate->checked)->toBe([]);
});

it('skips discovery with the "sign in with a password" link (break-glass)', function (): void {
    $discovery = adminLoginFakeDiscovery($this->provider);
    adminLoginFakeGate(null);
    $this->designer->forceFill(['email' => 'designer@acme.example'])->save();

    Livewire::test(Login::class)
        ->call('usePassword')
        ->assertSet('step', Login::STEP_PASSWORD)
        ->fillForm(['email' => 'designer@acme.example', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect($discovery->calls)->toBe([]);
    $this->assertAuthenticatedAs($this->designer);
});

it('refuses a password login the gate blocks before checking the password, with a link to SSO', function (): void {
    adminLoginFakeDiscovery();
    adminLoginFakeGate($this->provider);
    $boss = memberOf($this->org, SystemRole::Admin);
    $boss->forceFill(['email' => 'boss@acme.example', 'password' => 'password'])->save();

    $page = Livewire::test(Login::class)
        ->call('usePassword')
        ->fillForm(['email' => 'boss@acme.example', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasErrors(['data.email'])
        ->assertSet('ssoUrl', $this->provider->redirectUrl(SsoClient::Admin, 'boss@acme.example'))
        ->assertSee(__('sso_admin.login.sso_link'));

    expect($page->errors()->get('data.email'))->toBe([__('sso_admin.login.sso_required')]);
    $this->assertGuest();
    $blocked = Activity::query()->where('event', 'auth.password_login_blocked')->sole();
    expect($blocked->properties['via'])->toBe('admin')
        ->and(Activity::query()->where('event', 'auth.failed')->count())->toBe(0); // no password was checked
});

it('keeps the multi-factor challenge after the password step', function (): void {
    adminLoginFakeDiscovery();
    adminLoginFakeGate(null);
    $mfa = SharedAppAuthentication::make()->recoverable();
    $mfa->saveSecret($this->designer, $mfa->generateSecret());

    Livewire::test(Login::class)
        ->fillForm(['email' => 'designer@demo.test'])
        ->call('continueWithEmail')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null);

    $this->assertGuest();
});

it('keeps Filament\'s sign-in throttle', function (): void {
    adminLoginFakeDiscovery();
    adminLoginFakeGate(null);
    $page = Livewire::test(Login::class)->call('usePassword');

    foreach (range(1, 5) as $ignored) {
        $page->fillForm(['email' => 'designer@demo.test', 'password' => 'wrong'])->call('authenticate')->assertHasErrors(['data.email']);
    }
    $page->fillForm(['email' => 'designer@demo.test', 'password' => 'password'])->call('authenticate')
        ->assertNotified(__('filament-panels::auth/pages/login.notifications.throttled.title', ['seconds' => 60, 'minutes' => 1]));
    $this->assertGuest();
});

it('goes back to the email step', function (): void {
    adminLoginFakeDiscovery();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'designer@demo.test'])
        ->call('continueWithEmail')
        ->call('useOtherEmail')
        ->assertSet('step', Login::STEP_EMAIL)
        ->assertSet('data.email', 'designer@demo.test');
});

it('explains an SSO refusal the identity provider round trip returned', function (): void {
    Livewire::withQueryParams(['sso_error' => 'not_member'])->test(Login::class)
        ->assertNotified(__('sso_admin.login.refused.not_member'));
});

it('refuses a reset link for a domain that enforces SSO and audits it', function (): void {
    Notification::fake();
    adminLoginFakeGate($this->provider);
    $boss = memberOf($this->org, SystemRole::Admin);
    $boss->forceFill(['email' => 'boss@acme.example'])->save();

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'boss@acme.example'])
        ->call('request')
        ->assertHasErrors(['data.email']);

    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'auth.password_reset_blocked')->sole()->properties['via'])->toBe('admin_forgot');

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'designer@demo.test'])->call('request')->assertHasNoErrors();
    Notification::assertSentTo($this->designer, ResetPasswordNotification::class);
});

it('refuses to set a password through an old reset link once SSO is enforced', function (): void {
    adminLoginFakeGate($this->provider);
    $boss = memberOf($this->org, SystemRole::Admin);
    $boss->forceFill(['email' => 'boss@acme.example', 'password' => 'old-password'])->save();
    $token = app('auth.password.broker')->createToken($boss);

    Livewire::test(ResetPassword::class, ['email' => 'boss@acme.example', 'token' => $token])
        ->fillForm(['password' => 'N3w-Passw0rd!', 'passwordConfirmation' => 'N3w-Passw0rd!'])
        ->call('resetPassword')
        ->assertHasErrors(['email']);

    expect(Hash::check('old-password', $boss->fresh()->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'auth.password_reset_blocked')->sole()->properties['via'])->toBe('admin_reset');
});

it('offers "Sign in" (not "Continue") once the password step is shown', function (): void {
    adminLoginFakeDiscovery(null);
    adminLoginFakeGate(null);
    app()->setLocale('en');

    Livewire::test(Login::class)
        ->fillForm(['email' => 'designer@demo.test'])
        ->call('continueWithEmail')
        ->assertSet('step', Login::STEP_PASSWORD)
        ->assertSee(__('filament-panels::auth/pages/login.form.actions.authenticate.label'))
        ->assertDontSee(__('sso_admin.login.continue'))
        ->assertDontSee(__('sso_admin.login.use_password'));
});
