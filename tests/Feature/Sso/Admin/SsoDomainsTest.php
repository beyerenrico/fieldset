<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Domains\AddSsoDomain;
use App\Domain\Identity\Sso\Domains\ConfigTxtRecordResolver;
use App\Domain\Identity\Sso\Domains\RemoveSsoDomain;
use App\Domain\Identity\Sso\Domains\VerifySsoDomain;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Tests\Support\Sso\FakeTxtRecordResolver;

function ssoAdminAuditCount(string $event): int
{
    return Activity::query()->where('event', $event)->count();
}

/** Records reports instead of notifying. */
function ssoAdminFakeReporter(): object
{
    $fake = new class implements SsoMisconfigurationReporter
    {
        /** @var list<array{provider: int, reason: string, detail: string}> */
        public array $reports = [];

        public function report(IdentityProvider $p, string $reason, string $detail): void
        {
            $this->reports[] = ['provider' => $p->id, 'reason' => $reason, 'detail' => $detail];
        }
    };
    app()->instance(SsoMisconfigurationReporter::class, $fake);

    return $fake;
}

function ssoAdminClaimDomain(Organization $org, string $domain): SsoDomain
{
    return app(AddSsoDomain::class)->handle($org, $domain, ownerOf($org));
}

beforeEach(function (): void {
    $this->dns = FakeTxtRecordResolver::bind();
    $this->reporter = ssoAdminFakeReporter();
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->tenant = app(TenantContext::class);
});

it('claims a normalized domain once per organization and audits it once', function (): void {
    $domain = ssoAdminClaimDomain($this->org, ' Bücher.Example. ');

    expect($domain)->domain->toBe('xn--bcher-kva.example')->organization_id->toBe($this->org->id)
        ->created_by->toBe($this->owner->id)->isVerified()->toBeFalse()
        ->and(ssoAdminAuditCount('sso.domain_added'))->toBe(1)
        ->and(Activity::query()->where('subject_type', (new SsoDomain)->getMorphClass())->count())->toBe(1);

    expect(fn () => ssoAdminClaimDomain($this->org, 'BÜCHER.example'))->toThrow(ValidationException::class);
});

it('refuses invalid domains, addresses and public suffixes with a message on the domain field', function (string $input): void {
    try {
        ssoAdminClaimDomain($this->org, $input);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('domain');
    }
    expect(SsoDomain::query()->withoutTenant()->count())->toBe(0);
})->with(['boss@acme.example', '10.0.0.1', 'co.uk', 'com', 'https://acme.example']);

it('lets several organizations claim the same unverified domain', function (): void {
    $other = makeOrganization(name: 'Other');

    ssoAdminClaimDomain($this->org, 'acme.example');
    ssoAdminClaimDomain($other, 'acme.example');

    expect(SsoDomain::query()->withoutTenant()->where('domain', 'acme.example')->count())->toBe(2);
});

it('verifies a domain whose TXT record carries the token', function (): void {
    $domain = ssoAdminClaimDomain($this->org, 'acme.example');
    $this->dns->publish($domain, 'v=spf1 -all');

    expect(app(VerifySsoDomain::class)->handle($domain, $this->owner))->toBe(DomainVerification::Verified);

    $domain->refresh();
    expect($this->dns->queried)->toBe(['_form-schema-app-verification.acme.example'])
        ->and($domain->isVerified())->toBeTrue()
        ->and($domain->last_check_result)->toBe(DomainVerification::Verified)
        ->and($domain->failed_checks)->toBe(0)
        ->and(ssoAdminAuditCount('sso.domain_verified'))->toBe(1)
        ->and(Activity::query()->where('event', 'sso.domain_verified')->sole()->causer_id)->toBe($this->owner->id);
});

it('reports a missing or foreign record without verifying', function (): void {
    $domain = ssoAdminClaimDomain($this->org, 'acme.example');

    expect(app(VerifySsoDomain::class)->handle($domain, $this->owner))->toBe(DomainVerification::Missing);
    $this->dns->set($domain->txtName(), ['form-schema-app-verification=someone-else']);
    expect(app(VerifySsoDomain::class)->handle($domain, $this->owner))->toBe(DomainVerification::Mismatch);
    $this->dns->fail($domain->txtName());
    expect(app(VerifySsoDomain::class)->handle($domain, $this->owner))->toBe(DomainVerification::Error);

    $entry = Activity::query()->where('event', 'sso.domain_verification_failed')->latest('id')->first();
    expect($domain->refresh()->isVerified())->toBeFalse()
        ->and($domain->last_check_result)->toBe(DomainVerification::Error)
        ->and(ssoAdminAuditCount('sso.domain_verification_failed'))->toBe(3)
        ->and($entry->properties['result'])->toBe('error')
        ->and($entry->properties['scheduled'])->toBeFalse();
});

it('refuses to verify a domain another organization already verified', function (): void {
    $other = makeOrganization(name: 'Other');
    $theirs = ssoAdminClaimDomain($other, 'acme.example');
    $this->dns->publish($theirs);
    app(VerifySsoDomain::class)->handle($theirs, ownerOf($other));

    $ours = ssoAdminClaimDomain($this->org, 'acme.example');
    $this->dns->publish($ours);

    expect(fn () => app(VerifySsoDomain::class)->handle($ours, $this->owner))
        ->toThrow(ValidationException::class, __('sso_admin.domains.errors.verified_elsewhere'));
    expect($ours->refresh()->isVerified())->toBeFalse()
        ->and($theirs->refresh()->isVerified())->toBeTrue()
        ->and(Activity::query()->where('event', 'sso.domain_verification_failed')->sole()->properties['result'])->toBe('verified_elsewhere');
});

it('unverifies after the configured misses in a row, audits and reports a misconfiguration', function (): void {
    config(['sso.recheck.failures_before_unverify' => 3]);
    $provider = $this->tenant->run($this->org, fn (): IdentityProvider => IdentityProvider::factory()->active()->create());
    $domain = ssoAdminClaimDomain($this->org, 'acme.example');
    $this->dns->publish($domain);
    app(VerifySsoDomain::class)->handle($domain, $this->owner);
    $this->dns->forget($domain->txtName());

    $this->artisan('sso:recheck-domains')->assertSuccessful();
    $this->dns->fail($domain->txtName()); // a failed lookup is recorded but never counts as a miss
    $this->artisan('sso:recheck-domains')->assertSuccessful();
    $this->dns->failing = [];
    $this->artisan('sso:recheck-domains')->assertSuccessful();
    expect($domain->refresh())->isVerified()->toBeTrue()->failed_checks->toBe(2)
        ->and($this->reporter->reports)->toBe([]);

    $this->artisan('sso:recheck-domains')->assertSuccessful()->expectsOutputToContain('1 unverified');

    $entry = Activity::query()->where('event', 'sso.domain_unverified')->sole();
    expect($domain->refresh()->isVerified())->toBeFalse()
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->organization_id)->toBe($this->org->id)
        ->and($entry->properties['failed_checks'])->toBe(3)
        ->and($this->reporter->reports)->toBe([['provider' => $provider->id, 'reason' => 'domain_unverified', 'detail' => 'acme.example']]);

    $this->artisan('sso:recheck-domains')->assertSuccessful(); // unverified domains are no longer re-checked
    expect(ssoAdminAuditCount('sso.domain_unverified'))->toBe(1);
});

it('resets the miss counter when a re-check finds the record again', function (): void {
    $domain = ssoAdminClaimDomain($this->org, 'acme.example');
    $this->dns->publish($domain);
    app(VerifySsoDomain::class)->handle($domain, $this->owner);
    $this->dns->forget($domain->txtName());
    $this->artisan('sso:recheck-domains');
    $this->dns->publish($domain);
    $this->artisan('sso:recheck-domains')->expectsOutputToContain('1 verified');

    expect($domain->refresh())->failed_checks->toBe(0)->isVerified()->toBeTrue()
        ->and(ssoAdminAuditCount('sso.domain_verified'))->toBe(1);
});

it('re-checks the verified domains of every organization without a tenant', function (): void {
    $other = makeOrganization(name: 'Other');
    $domains = [ssoAdminClaimDomain($this->org, 'a.example'), ssoAdminClaimDomain($other, 'b.example'), ssoAdminClaimDomain($other, 'pending.example')];
    foreach (array_slice($domains, 0, 2) as $domain) {
        $this->dns->publish($domain);
        app(VerifySsoDomain::class)->handle($domain);
    }
    $this->tenant->clear();
    $this->dns->queried = [];

    $this->artisan('sso:recheck-domains')->expectsOutputToContain('2 verified');
    expect($this->dns->queried)->toBe(['_form-schema-app-verification.a.example', '_form-schema-app-verification.b.example']);
});

it('removes a claim with one audit entry but keeps the last verified domain while SSO is enforced', function (): void {
    $domain = ssoAdminClaimDomain($this->org, 'acme.example');
    app(RemoveSsoDomain::class)->handle($domain, $this->owner);
    expect(SsoDomain::query()->withoutTenant()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'sso.domain_removed')->sole()->properties['domain'])->toBe('acme.example')
        ->and(Activity::query()->where('subject_type', (new SsoDomain)->getMorphClass())->where('event', 'deleted')->count())->toBe(0);

    $verified = ssoAdminClaimDomain($this->org, 'acme.example');
    $this->dns->publish($verified);
    app(VerifySsoDomain::class)->handle($verified, $this->owner);
    $this->tenant->run($this->org, fn () => IdentityProvider::factory()->enforcing($this->owner)->create());

    expect(fn () => app(RemoveSsoDomain::class)->handle($verified->refresh(), $this->owner))->toThrow(ValidationException::class);
    expect($verified->exists)->toBeTrue();
});

it('reads TXT records from config in development and CI', function (): void {
    config(['sso.dns.records' => ['_Form-Schema-App-Verification.Acme.Example.' => ['form-schema-app-verification=abc', 7]]]);
    $resolver = new ConfigTxtRecordResolver;

    expect($resolver->txt('_form-schema-app-verification.acme.example'))->toBe(['form-schema-app-verification=abc'])
        ->and($resolver->txt('_form-schema-app-verification.other.example'))->toBe([]);
});
