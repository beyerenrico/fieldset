<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLabels;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\IssueLoginLink;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Support\Facades\Artisan;

/*
 * The app side of `fieldset config set` (plan U11, KTD25, KTD26; ADR 0077): `instance:url-impact` lists the organizations
 * whose SSO URLs follow the instance URL and counts links already sent with the old address; `instance:config-changed`
 * writes the `instance.config_changed` audit entry by the command line with the host UID. Both print one JSON
 * document, as the socket-less one-offs fieldset runs.
 */

/** @return array{0: array<string, mixed>, 1: int} */
function instanceCommandJson(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, [...$arguments, '--json' => true]);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));
    expect($lines)->toHaveCount(1);

    return [json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR), $exit];
}

it('lists every organization with SSO across tenants and counts the links that keep the old address', function (): void {
    $saml = makeOrganization(name: 'Saml Org');
    $oidc = makeOrganization(name: 'Oidc Org');
    makeOrganization(name: 'Plain Org');
    app(TenantContext::class)->run($saml, fn () => IdentityProvider::factory()->saml()->active()->create(['name' => 'Entra']));
    app(TenantContext::class)->run($oidc, fn () => IdentityProvider::factory()->create(['status' => IdentityProviderStatus::Disabled]));
    $invited = memberOf($saml, SystemRole::FieldWorker);
    Membership::query()->where('user_id', $invited->id)->update(['status' => MembershipStatus::Invited, 'invited_at' => now()->subDays(2)]);
    $expired = memberOf($oidc, SystemRole::FieldWorker);
    Membership::query()->where('user_id', $expired->id)->update(['status' => MembershipStatus::Invited, 'invited_at' => now()->subDays(8)]);
    app(TenantContext::class)->run($saml, function (): void {
        DocumentLink::factory()->create();
        DocumentLink::factory()->create(['expires_at' => now()->subDay()]);
        DataExport::factory()->create(['status' => 'completed', 'expires_at' => now()->addDay()]);
    });
    app(IssueLoginLink::class)->handle(ownerOf($saml), 501);

    [$json, $exit] = instanceCommandJson('instance:url-impact');

    $raw = json_encode($json);
    expect($exit)->toBe(0)
        ->and($json)->toMatchArray(['schema_version' => 1, 'command' => 'instance:url-impact', 'status' => 'ok', 'errors' => [], 'secrets' => []])
        ->and($json['data']['sso_organizations'])->toHaveCount(2)
        ->and($json['data']['sso_organizations'][0])->toBe([
            'organization' => ['id' => $saml->id, 'name' => 'Saml Org', 'slug' => $saml->slug],
            'provider' => ['type' => 'saml', 'name' => 'Entra', 'status' => 'active'],
            'follows_url' => ['entity_id', 'acs_url'],
        ])
        ->and($json['data']['sso_organizations'][1]['provider'])->toMatchArray(['type' => 'oidc', 'status' => 'disabled'])
        ->and($json['data']['sso_organizations'][1]['follows_url'])->toBe(['redirect_uri'])
        ->and($json['data']['links'])->toBe(['invitations' => 1, 'document_links' => 1, 'login_links' => 1, 'data_exports' => 1])
        ->and($raw)->not->toContain('client_secret')->not->toContain('metadata_url');
});

it('lists the SSO provider of a deleted organization too (it would break the same way once restored)', function (): void {
    $org = makeOrganization(name: 'Gone Org');
    app(TenantContext::class)->run($org, fn () => IdentityProvider::factory()->saml()->create());
    $org->delete();

    [$json, $exit] = instanceCommandJson('instance:url-impact');

    expect($exit)->toBe(0)
        ->and($json['data']['sso_organizations'])->toHaveCount(1)
        ->and($json['data']['sso_organizations'][0]['organization']['name'])->toBe('Gone Org');
});

it('answers for a human without --json', function (): void {
    $this->artisan('instance:url-impact')->expectsOutputToContain('0 organization(s) with SSO')->assertSuccessful();
});

it('audits a changed setting as instance.config_changed by the command line with the host UID', function (): void {
    [$json, $exit] = instanceCommandJson('instance:config-changed', [
        '--key' => 'domain', '--old' => '', '--new' => 'forms.example.com',
        '--old-url' => 'http://localhost:8080', '--new-url' => 'https://forms.example.com', '--actor-uid' => '501',
    ]);

    $activity = Activity::query()->where('event', 'instance.config_changed')->sole();
    expect($exit)->toBe(0)
        ->and($json)->toMatchArray(['status' => 'ok', 'changed' => true])
        ->and($json['data'])->toMatchArray(['event' => 'instance.config_changed', 'activity_id' => $activity->id])
        ->and($activity->actor_type)->toBe('cli')
        ->and($activity->causer_id)->toBeNull()
        ->and($activity->organization_id)->toBeNull()
        ->and($activity->properties->all())->toMatchArray([
            'key' => 'domain', 'from' => null, 'to' => 'forms.example.com', 'host_uid' => 501,
            'url' => ['from' => 'http://localhost:8080', 'to' => 'https://forms.example.com'],
        ]);
});

it('labels instance.config_changed and its setting in English and German', function (): void {
    app()->setLocale('de');
    expect(AuditLabels::event('instance.config_changed'))->toBe('Instanzeinstellung geändert')
        ->and(trans('audit.attributes.key'))->toBe('Einstellung');
    app()->setLocale('en');
    expect(AuditLabels::event('instance.config_changed'))->toBe('Instance setting changed');
});

it('refuses malformed input without writing an entry', function (array $arguments): void {
    [$json, $exit] = instanceCommandJson('instance:config-changed', $arguments);

    expect($exit)->toBe(1)
        ->and($json['errors'][0]['code'])->toBe('invalid_input')
        ->and(Activity::query()->where('event', 'instance.config_changed')->count())->toBe(0);
})->with([
    'no key' => [['--new' => 'x']],
    'key with spaces' => [['--key' => 'do main']],
    'line break' => [['--key' => 'domain', '--new' => "a\nb"]],
    'bad uid' => [['--key' => 'domain', '--actor-uid' => 'root']],
]);

it('reports malformed input to a human with exit 1', function (): void {
    $this->artisan('instance:config-changed', ['--key' => 'Bad Key'])->expectsOutputToContain('--key must name a setting')->assertFailed();
});
