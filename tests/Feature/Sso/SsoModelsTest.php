<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Contracts\ProviderHealthCheck;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Contracts\SsoProviderFactory;
use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;
use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Discovery\VerifiedDomainDiscovery;
use App\Domain\Identity\Sso\Domains\ConfigTxtRecordResolver;
use App\Domain\Identity\Sso\Domains\DnsTxtRecordResolver;
use App\Domain\Identity\Sso\Enforcement\DomainPasswordGate;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Health\FetchProviderHealth;
use App\Domain\Identity\Sso\Health\NotifySsoMisconfiguration;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Runs a statement that must violate a constraint inside a savepoint (LEARNINGS #11). */
function expectConstraintViolation(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

/** @return array{0: Organization, 1: IdentityProvider} */
function organizationWithProvider(string $name = 'Acme GmbH'): array
{
    $org = makeOrganization(name: $name);
    $provider = app(TenantContext::class)->run($org, fn (): IdentityProvider => IdentityProvider::factory()->create());

    return [$org, $provider];
}

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
});

it('encrypts the provider config and client secret at rest and decrypts them on read', function (): void {
    [, $provider] = organizationWithProvider();
    $raw = DB::table('identity_providers')->where('id', $provider->id)->first();

    expect($raw->config)->not->toContain('form-schema-app')->not->toContain('issuer')
        ->and($raw->client_secret)->not->toBe($provider->client_secret)
        ->and(strlen((string) $raw->client_secret))->toBeGreaterThan(40)
        ->and(IdentityProvider::query()->withoutTenant()->findOrFail($provider->id))
        ->config->toMatchArray(['client_id' => 'form-schema-app', 'flavor' => 'generic'])
        ->client_secret->toBe($provider->client_secret);
});

it('never serializes the config or the client secret', function (): void {
    [, $provider] = organizationWithProvider();

    expect($provider->toArray())->not->toHaveKey('client_secret')->not->toHaveKey('config')
        ->and($provider->toJson())->not->toContain($provider->client_secret)
        ->and($provider->uuid)->toBeUuid()
        ->and($provider->hasClientSecret())->toBeTrue();
});

it('keeps config and secret out of the audit log', function (): void {
    [$org] = organizationWithProvider();
    $entry = Activity::query()->where('subject_type', (new IdentityProvider)->getMorphClass())->where('event', 'created')->sole();

    expect($entry->organization_id)->toBe($org->id)
        ->and(json_encode($entry->attribute_changes))->not->toContain('client_secret')->not->toContain('issuer');
});

it('allows one provider per organization', function (): void {
    [$org] = organizationWithProvider();

    expectConstraintViolation(fn () => $this->tenant->run($org, fn () => IdentityProvider::factory()->create()));
});

it('lets many organizations claim a domain but only one verify it', function (): void {
    $a = makeOrganization(name: 'A');
    $b = makeOrganization(name: 'B');
    $claimA = $this->tenant->run($a, fn (): SsoDomain => SsoDomain::factory()->create(['domain' => 'example.com']));
    $claimB = $this->tenant->run($b, fn (): SsoDomain => SsoDomain::factory()->create(['domain' => 'example.com']));

    $this->tenant->run($a, fn (): bool => $claimA->forceFill(['verified_at' => now()])->save());
    expectConstraintViolation(fn () => $this->tenant->run($b, fn (): bool => $claimB->forceFill(['verified_at' => now()])->save()));
    expectConstraintViolation(fn () => $this->tenant->run($a, fn () => SsoDomain::factory()->create(['domain' => 'example.com'])));
    expectConstraintViolation(fn () => $this->tenant->run($a, fn () => SsoDomain::factory()->create(['domain' => 'Upper.example'])));

    $this->tenant->clear();
    expect(SsoDomain::verifiedOwnerOf(' Example.COM '))->id->toBe($claimA->id)
        ->and(SsoDomain::verifiedOwnerOf('other.example'))->toBeNull();
});

it('names the verification TXT record after the domain and the token', function (): void {
    $org = makeOrganization();
    $domain = $this->tenant->run($org, fn (): SsoDomain => SsoDomain::factory()->create(['domain' => 'acme.example']));

    expect($domain->txtName())->toBe('_form-schema-app-verification.acme.example')
        ->and($domain->txtValue())->toBe('form-schema-app-verification='.$domain->verification_token)
        ->and($domain->verification_token)->toMatch('/^[0-9a-f]{40}$/')
        ->and($domain->isVerified())->toBeFalse();
});

it('links a subject to one user per provider', function (): void {
    [$org, $provider] = organizationWithProvider();
    $identity = $this->tenant->run($org, fn (): ExternalIdentity => ExternalIdentity::factory()->create(['subject' => 'kc-123']));

    expect($identity->identity_provider_id)->toBe($provider->id);
    expectConstraintViolation(fn () => $this->tenant->run($org, fn () => ExternalIdentity::factory()->create(['subject' => 'kc-123'])));

    [$otherOrg] = organizationWithProvider('Other');
    $foreign = $this->tenant->run($otherOrg, fn (): ExternalIdentity => ExternalIdentity::factory()->create(['subject' => 'kc-123']));
    expect($foreign->identity_provider_id)->not->toBe($provider->id);

    $this->tenant->set($org);
    expect($identity->user->externalIdentities()->pluck('id')->all())->toBe([$identity->id]);
});

it('maps a claim value to a role xor a group', function (): void {
    [$org, $provider] = organizationWithProvider();
    $this->tenant->set($org);
    $role = Role::query()->where('organization_id', $org->id)->firstOrFail();
    $group = Group::factory()->create();
    $mapping = fn (array $target, string $value): IdentityProviderMapping => IdentityProviderMapping::factory()
        ->create(['identity_provider_id' => $provider->id, 'value' => $value, 'role_id' => null, 'group_id' => null, ...$target]);

    expect($mapping(['role_id' => $role->id], 'admins')->role?->is($role))->toBeTrue()
        ->and($mapping(['group_id' => $group->id], 'crew')->group?->is($group))->toBeTrue()
        ->and($provider->mappings()->count())->toBe(2);
    expectConstraintViolation(fn () => $mapping([], 'none'));
    expectConstraintViolation(fn () => $mapping(['role_id' => $role->id, 'group_id' => $group->id], 'both'));
    expectConstraintViolation(fn () => $mapping(['role_id' => $role->id], 'admins'));
});

it('resolves the provider route binding by uuid without a tenant', function (): void {
    [, $provider] = organizationWithProvider();
    $this->tenant->clear();
    Route::middleware('web')->get('_test/sso/{provider}', fn (IdentityProvider $provider): string => $provider->uuid);

    expect((new IdentityProvider)->resolveRouteBinding($provider->uuid)?->id)->toBe($provider->id)
        ->and((new IdentityProvider)->resolveRouteBinding('not-a-uuid'))->toBeNull()
        ->and((new IdentityProvider)->resolveRouteBinding((string) $provider->id))->toBeNull();
    $this->get('_test/sso/'.$provider->uuid)->assertOk()->assertSee($provider->uuid);
    $this->get('_test/sso/00000000-0000-4000-8000-000000000000')->assertNotFound();
});

it('builds the SSO URLs from the provider uuid', function (): void {
    [, $provider] = organizationWithProvider();
    $base = url('auth/sso/'.$provider->uuid);

    expect($provider->redirectUrl(SsoClient::App))->toBe($base.'/redirect?client=app')
        ->and($provider->redirectUrl(SsoClient::Admin, 'a@acme.example', true))->toBe($base.'/redirect?client=admin&login_hint=a%40acme.example&test=1')
        ->and($provider->callbackUrl())->toBe($base.'/callback')
        ->and($provider->samlAcsUrl())->toBe($base.'/saml/acs')
        ->and($provider->samlEntityId())->toBe($base.'/saml/metadata');
});

it('binds every SSO contract to its final class with safe skeleton defaults', function (): void {
    expect(app(HomeRealmDiscovery::class))->toBeInstanceOf(VerifiedDomainDiscovery::class)
        ->and(app(PasswordAuthGate::class))->toBeInstanceOf(DomainPasswordGate::class)
        ->and(app(SsoProviderFactory::class))->toBeInstanceOf(DynamicProviderFactory::class)
        ->and(app(ProviderHealthCheck::class))->toBeInstanceOf(FetchProviderHealth::class)
        ->and(app(SsoMisconfigurationReporter::class))->toBeInstanceOf(NotifySsoMisconfiguration::class)
        ->and(app(TxtRecordResolver::class))->toBeInstanceOf(ConfigTxtRecordResolver::class);

    $discovery = app(HomeRealmDiscovery::class)->discover('worker@acme.example', SsoClient::App);
    expect($discovery->isSso())->toBeFalse()->and($discovery->provider)->toBeNull()
        ->and(app(TxtRecordResolver::class)->txt('_form-schema-app-verification.acme.example'))->toBe([]);
    app(PasswordAuthGate::class)->assertPasswordLogin('worker@acme.example');
    app(PasswordAuthGate::class)->assertPasswordReset('worker@acme.example');

    config(['sso.dns.driver' => 'dns']);
    expect(app(TxtRecordResolver::class))->toBeInstanceOf(DnsTxtRecordResolver::class);
});

it('answers discovery in password mode and keeps the ACS route free of session and CSRF', function (): void {
    $this->postJson('auth/sso/discover', ['email' => 'worker@acme.example'])->assertOk()->assertExactJson(['mode' => 'password']);

    $acs = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('sso.saml.acs'));
    $callback = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('sso.callback'));
    expect($acs)->not->toContain(StartSession::class)->not->toContain(ShareErrorsFromSession::class)->not->toContain(PreventRequestForgery::class)
        ->and($callback)->toContain(StartSession::class);
});

it('sends no reset link when the gate requires SSO and audits the block', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'boss@acme.example']);

    $user->sendPasswordResetNotification('token');
    Notification::assertSentTo($user, ResetPassword::class);

    app()->instance(PasswordAuthGate::class, new class implements PasswordAuthGate
    {
        public function assertPasswordLogin(string $email): void {}

        public function assertPasswordLoginWith(string $email, string $password): void {}

        public function assertPasswordReset(string $email): void
        {
            throw new SsoRequiredException(DiscoveryResult::password());
        }

        public function isBreakGlass(User $user): bool
        {
            return false;
        }
    });
    Notification::fake();
    $user->sendPasswordResetNotification('token');

    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'auth.password_reset_blocked')->where('subject_id', $user->id)->count())->toBe(1);
});
