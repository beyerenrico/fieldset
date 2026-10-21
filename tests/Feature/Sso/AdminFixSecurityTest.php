<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\SaveIdentityProvider;
use App\Domain\Identity\Sso\Admin\SetSsoEnforcement;
use App\Domain\Identity\Sso\Admin\SsoSecurityAlerts;
use App\Domain\Identity\Sso\Domains\RemoveSsoDomain;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/* Gate review M7, FS-admin items 1, 2, 4, 13: Owner-only SSO security settings, the JIT default role guard,
   ending password sessions on enforcement and the flavor / NameID rules. */

/** @return array<string, list<string>> */
function adminFixErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return $e->errors();
    }
    test()->fail('expected a validation error');
}

function adminFixWithMfa(User $user): User
{
    $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    return $user->refresh();
}

/** @param  array<string, mixed>  $overrides */
function adminFixSave(User $actor, array $overrides = [], ?string $secret = null, IdentityProviderType $type = IdentityProviderType::Oidc): IdentityProvider
{
    $config = $type === IdentityProviderType::Oidc
        ? ['flavor' => 'generic', 'issuer' => 'https://idp.acme.example/realms/acme', 'client_id' => 'fieldset', 'scopes' => ['openid', 'email', 'profile']]
        : ['metadata_url' => 'https://idp.acme.example/saml/descriptor'];

    return app(SaveIdentityProvider::class)->handle(test()->org, $type, (string) ($overrides['name'] ?? 'Acme SSO'), [...$config, ...$overrides], $secret, $actor);
}

function adminFixSessions(User ...$users): void
{
    foreach ($users as $user) {
        DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $user->forceFill(['remember_token' => 'remember-'.$user->id])->save();
    }
}

beforeEach(function (): void {
    $this->notifier = new FakeNotifier;
    app()->instance(Notifier::class, $this->notifier);
    $this->org = makeOrganization();
    $this->owner = adminFixWithMfa(ownerOf($this->org));
    $this->admin = memberOf($this->org, SystemRole::Admin);
    $this->tenant = app(TenantContext::class);
    $this->tenant->set($this->org);
});

it('lets only an Owner set up a provider', function (): void {
    expect(adminFixErrors(fn () => adminFixSave($this->admin, secret: 's3cret')))->toHaveKeys(['type', 'flavor', 'issuer', 'client_id'])
        ->and(IdentityProvider::query()->count())->toBe(0);

    $coOwner = memberOf($this->org, SystemRole::Owner);
    $this->tenant->set($this->org);
    adminFixSave($this->owner, secret: 's3cret');

    $sent = $this->notifier->sentFor(NotificationEvent::SsoMisconfigured);
    expect(IdentityProvider::query()->count())->toBe(1)
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['message']->titleKey)->toBe('sso_admin.alerts.'.SsoSecurityAlerts::CONNECTION_CREATED.'.title')
        ->and($sent[0]['recipients'])->toBe([$coOwner->id]); // the other Owners, not the actor
});

it('refuses every Owner-only setting to an Admin and keeps the rest open', function (string $key, mixed $value): void {
    if ($value === 'worker-role') {
        $value = Role::query()->where('organization_id', $this->org->id)->where('name', SystemRole::FieldWorker->value)->sole()->id;
    }
    $saml = in_array($key, ['email_attribute', 'name_attribute', 'groups_attribute', 'name_id_format', 'subject_attribute', 'metadata_url'], true);
    $type = $saml ? IdentityProviderType::Saml : IdentityProviderType::Oidc;
    adminFixSave($this->owner, ['subject_attribute' => $key === 'name_id_format' ? 'objectId' : null], secret: $saml ? null : 's3cret', type: $type);
    $overrides = [$key => $value, ...($key === 'name_id_format' ? ['subject_attribute' => 'objectId'] : [])];

    expect(adminFixErrors(fn () => adminFixSave($this->admin, $overrides, type: $type))[$key] ?? null)->toBe([__('sso_admin.provider.errors.owner_only')])
        ->and(Activity::query()->where('event', 'sso.provider_updated')->count())->toBe(0);
    $this->tenant->set($this->org);
    expect(adminFixSave($this->owner, $overrides, type: $type)->exists)->toBeTrue(); // the Owner may
})->with([
    'issuer' => ['issuer', 'https://attacker.example/realms/evil'],
    'client id' => ['client_id', 'attacker-client'],
    'flavor' => ['flavor', 'google'],
    'trust unverified email' => ['trust_unverified_email', true],
    'groups claim' => ['groups_claim', 'nickname'],
    'JIT default role' => ['default_role_id', 'worker-role'],
    'SAML metadata' => ['metadata_url', 'https://attacker.example/saml'],
    'SAML email attribute' => ['email_attribute', 'displayName'],
    'SAML name attribute' => ['name_attribute', 'cn'],
    'SAML groups attribute' => ['groups_attribute', 'department'],
    'SAML NameID format' => ['name_id_format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified'],
    'SAML subject attribute' => ['subject_attribute', 'nickname'],
]);

it('lets an Admin rename, rotate the secret and change scopes, JIT and the default group', function (): void {
    adminFixSave($this->owner, secret: 's3cret');

    $provider = adminFixSave($this->admin, ['name' => 'Renamed', 'scopes' => ['openid', 'email'], 'jit_enabled' => true, 'force_reauth' => true], 'rotated');

    expect($provider)->name->toBe('Renamed')->client_secret->toBe('rotated')->jit_enabled->toBeTrue()
        ->and($provider->config['scopes'])->toBe(['openid', 'email']);
});

it('unlinks the identities, audits and alerts the other Owners when an Owner changes the connection', function (): void {
    $coOwner = memberOf($this->org, SystemRole::Owner);
    $this->tenant->set($this->org);
    $provider = adminFixSave($this->owner, secret: 's3cret');
    $provider->forceFill(['status' => IdentityProviderStatus::Active])->save();
    ExternalIdentity::factory()->count(2)->create(['identity_provider_id' => $provider->id]);
    $this->notifier->sent = [];

    adminFixSave($this->owner, ['name' => 'Acme SSO 2']); // no connection change: identities stay
    expect(ExternalIdentity::query()->count())->toBe(2)->and($this->notifier->sent)->toBe([]);

    adminFixSave($this->owner, ['name' => 'Acme SSO 2', 'issuer' => 'https://new-idp.acme.example/realms/acme']);

    $audit = Activity::query()->where('event', 'sso.connection_changed')->sole();
    $sent = $this->notifier->sentFor(NotificationEvent::SsoMisconfigured);
    expect(ExternalIdentity::query()->count())->toBe(0)
        ->and($provider->refresh()->status)->toBe(IdentityProviderStatus::Draft)
        ->and($audit->properties['changed'])->toBe(['config.issuer'])
        ->and($audit->properties['identities_removed'])->toBe(2)
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and(json_encode($audit->properties))->not->toContain('new-idp')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['message']->titleKey)->toBe('sso_admin.alerts.connection_changed.title')
        ->and($sent[0]['message']->params)->toMatchArray(['provider' => 'Acme SSO 2', 'actor' => $this->owner->name])
        ->and($sent[0]['recipients'])->toBe([$coOwner->id]);
});

it('requires the JIT default role to be one the actor may assign', function (): void {
    $limited = memberOf($this->org, SystemRole::FieldWorker);
    $this->tenant->run($this->org, fn () => $limited->givePermissionTo([Permission::OrganizationDelete->value, Permission::MembersManage->value, Permission::AdminAccess->value]));
    $this->tenant->set($this->org);
    $viewer = Role::query()->where('organization_id', $this->org->id)->where('name', SystemRole::Viewer->value)->sole();
    $worker = Role::query()->where('organization_id', $this->org->id)->where('name', SystemRole::FieldWorker->value)->sole();

    expect(adminFixErrors(fn () => adminFixSave($limited, ['default_role_id' => $viewer->id], 's3cret'))['default_role_id'])
        ->toBe([__('sso_admin.provider.errors.role_not_assignable')]);

    expect(adminFixSave($limited, ['default_role_id' => $worker->id], 's3cret')->default_role_id)->toBe($worker->id);
    adminFixSave($this->owner, ['default_role_id' => $viewer->id]);
    expect(adminFixSave($limited, ['default_role_id' => $viewer->id, 'name' => 'Renamed'])->name)->toBe('Renamed'); // stored role: not re-assigned
});

it('lets only an Owner switch enforcement off, and alerts the other Owners', function (): void {
    $coOwner = memberOf($this->org, SystemRole::Owner);
    $this->tenant->set($this->org);
    $provider = IdentityProvider::factory()->active()->create();
    SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
    ExternalIdentity::factory()->create(['identity_provider_id' => $provider->id, 'user_id' => $this->admin->id]);
    app(SetSsoEnforcement::class)->handle($provider, true, $this->owner, $this->admin); // switching on stays open to Admins

    expect(adminFixErrors(fn () => app(SetSsoEnforcement::class)->handle($provider, false, null, $this->admin))['enforce_sso'])
        ->toBe([__('sso_admin.enforcement.errors.owner_only_disable')])
        ->and($provider->refresh()->enforce_sso)->toBeTrue()
        ->and($this->notifier->sent)->toBe([]);

    app(SetSsoEnforcement::class)->handle($provider, false, null, $this->owner);

    $sent = $this->notifier->sentFor(NotificationEvent::SsoMisconfigured);
    expect($provider->refresh()->enforce_sso)->toBeFalse()
        ->and(Activity::query()->where('event', 'sso.enforcement_changed')->latest('id')->first()->properties['after']['enforce_sso'])->toBeFalse()
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['message']->titleKey)->toBe('sso_admin.alerts.enforcement_disabled.title')
        ->and($sent[0]['recipients'])->toBe([$coOwner->id]);
});

it('ends password sessions and remember tokens of the enforced domains when enforcement is switched on', function (): void {
    $provider = IdentityProvider::factory()->active()->create();
    SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
    SsoDomain::factory()->create(['domain' => 'pending.example']);
    $this->owner->forceFill(['email' => 'boss@acme.example'])->save();
    $legacy = memberOf($this->org, SystemRole::Designer);
    $linked = memberOf($this->org, SystemRole::Reviewer);
    $outsider = memberOf($this->org, SystemRole::Viewer);
    $unverified = memberOf($this->org, SystemRole::Viewer);
    $elsewhere = memberOf(makeOrganization(name: 'Other'), SystemRole::Admin);
    $this->tenant->set($this->org);
    $legacy->forceFill(['email' => 'Legacy@ACME.example'])->save();
    $linked->forceFill(['email' => 'linked@acme.example'])->save();
    $outsider->forceFill(['email' => 'someone@acme.example.org'])->save();
    $unverified->forceFill(['email' => 'someone@pending.example'])->save();
    $elsewhere->forceFill(['email' => 'contractor@acme.example'])->save();
    ExternalIdentity::factory()->create(['identity_provider_id' => $provider->id, 'user_id' => $linked->id]);
    adminFixSessions($this->owner, $legacy, $linked, $outsider, $unverified, $elsewhere);

    app(SetSsoEnforcement::class)->handle($provider, true, $this->owner, $this->owner);

    $remaining = DB::table('sessions')->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
    expect($remaining)->toBe(collect([$this->owner->id, $linked->id, $outsider->id, $unverified->id])->sort()->values()->all())
        ->and($legacy->fresh()->remember_token)->toBeNull()
        ->and($elsewhere->fresh()->remember_token)->toBeNull()
        ->and($this->owner->fresh()->remember_token)->toBe('remember-'.$this->owner->id)
        ->and($linked->fresh()->remember_token)->toBe('remember-'.$linked->id)
        ->and(Activity::query()->where('event', 'sso.enforcement_changed')->sole()->properties['sessions_ended'])->toBe(2);

    app(SetSsoEnforcement::class)->handle($provider, true, $this->owner, $this->owner); // already on: nothing ends
    expect(DB::table('sessions')->count())->toBe(4);
});

it('refuses removing a verified domain under enforcement to an Admin', function (): void {
    $provider = IdentityProvider::factory()->active()->create(['enforce_sso' => true, 'break_glass_user_id' => $this->owner->id]);
    $first = SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
    $second = SsoDomain::factory()->verified()->create(['domain' => 'acme-group.example']);
    $pending = SsoDomain::factory()->create(['domain' => 'pending.example']);

    expect(adminFixErrors(fn () => app(RemoveSsoDomain::class)->handle($second, $this->admin))['domain'])
        ->toBe([__('sso_admin.domains.errors.owner_only_enforced')]);
    app(RemoveSsoDomain::class)->handle($pending, $this->admin); // unverified: no effect on enforcement
    app(RemoveSsoDomain::class)->handle($second, $this->owner);

    expect(SsoDomain::query()->pluck('id')->all())->toBe([$first->id])
        ->and($provider->refresh()->enforce_sso)->toBeTrue();
});

it('refuses the generic flavor for Google and Microsoft issuers', function (string $issuer, string $message): void {
    expect(adminFixErrors(fn () => adminFixSave($this->owner, ['issuer' => $issuer], 's3cret'))['issuer'])->toBe([__($message)]);
})->with([
    'Google' => ['https://accounts.google.com', 'sso_admin.provider.errors.use_google_flavor'],
    'Entra v2' => ['https://login.microsoftonline.com/0b3f8e7e-4a51-4c1f-9d2e-6f0a1b2c3d4e/v2.0', 'sso_admin.provider.errors.use_entra_flavor'],
    'Entra v1' => ['https://sts.windows.net/0b3f8e7e-4a51-4c1f-9d2e-6f0a1b2c3d4e/', 'sso_admin.provider.errors.use_entra_flavor'],
]);

it('defaults new SAML providers to persistent NameIDs and keeps the implicit format of older ones', function (): void {
    $saml = adminFixSave($this->owner, type: IdentityProviderType::Saml);
    expect($saml->config['name_id_format'])->toBe('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent');

    $saml->forceFill(['config' => [...$saml->config, 'name_id_format' => null]])->save(); // as seeded before the format existed
    expect(adminFixSave($this->owner, ['name' => 'Renamed'], type: IdentityProviderType::Saml)->config['name_id_format'])->toBeNull()
        ->and(adminFixErrors(fn () => adminFixSave($this->owner, ['name_id_format' => 'urn:evil'], type: IdentityProviderType::Saml)))->toHaveKey('name_id_format');
});

it('requires a stable subject attribute unless the NameID is persistent', function (string $format): void {
    expect(adminFixErrors(fn () => adminFixSave($this->owner, ['name_id_format' => $format], type: IdentityProviderType::Saml))['subject_attribute'])
        ->toBe([__('sso_admin.provider.errors.subject_attribute_required')]);

    $saml = adminFixSave($this->owner, ['name_id_format' => $format, 'subject_attribute' => 'objectId'], type: IdentityProviderType::Saml);
    expect($saml->config)->toMatchArray(['name_id_format' => $format, 'subject_attribute' => 'objectId']);
})->with([
    'email' => ['urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'],
    'transient' => ['urn:oasis:names:tc:SAML:2.0:nameid-format:transient'],
    'unspecified' => ['urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified'],
]);

it('treats dropping stored runtime-only connection keys as an Owner-only change', function (string $key, mixed $value, IdentityProviderType $type): void {
    $provider = adminFixSave($this->owner, secret: $type === IdentityProviderType::Oidc ? 's3cret' : null, type: $type);
    $provider->forceFill(['config' => [...$provider->config, $key => $value]])->save(); // written outside the admin (seed, console)

    expect(adminFixErrors(fn () => adminFixSave($this->admin, ['name' => 'Renamed'], type: $type)))->toHaveKey($key);
    $this->tenant->set($this->org);
    expect(adminFixSave($this->owner, ['name' => 'Renamed'], type: $type)->config)->not->toHaveKey($key);
})->with([
    'discovery URL' => ['discovery_url', 'https://idp.acme.example/realms/acme/.well-known/openid-configuration', IdentityProviderType::Oidc],
    'unverified-email alias' => ['allow_unverified_email', true, IdentityProviderType::Oidc],
    'SAML IdP entity id' => ['idp_entity_id', 'https://idp.acme.example', IdentityProviderType::Saml],
]);
