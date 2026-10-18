<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11 (R21, R31, R32, R54, KTD26; ADR 0077): `fsa config set domain` against scripted Docker. Local → domain
 * switches mode and URL through the renderer, recreates only what changed, waits for the certificate and audits;
 * a failed ACME order reverts to local with `reverted: true`; organizations with SSO are listed before (exit 6
 * without --yes); the same value again changes nothing without a Docker call. The real thing:
 * tests/Install/Journeys/ConfigDomainSwitchTest.php (Pebble).
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

it('switches local mode to domain mode, recreating only the services whose definition changed', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('domain', 'Forms.Example.com', ['--yes' => true, '--acme-email' => 'ops@example.com']);

    $state = $this->config->state();
    $settings = InstanceSettings::of($state);
    $up = $this->config->callsSince($mark, 'composeUp');
    $app = new EnvFiles($this->config->instance->directory)->read('app');
    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toMatchArray(['key' => 'domain', 'from' => null, 'to' => ConfigFixture::DOMAIN, 'url' => 'https://'.ConfigFixture::DOMAIN, 'mode' => 'domain', 'reverted' => false, 'audited' => true])
        ->and($json['data']['previous'])->toBe(['url' => 'http://localhost:8080', 'mode' => 'local'])
        ->and($json['data']['services'])->toBe(['recreated' => ['app', 'queue', 'scheduler', 'mailpit'], 'added' => ['edge'], 'removed' => []])
        ->and($json['data']['checked'])->toContain('https://'.ConfigFixture::DOMAIN.'/up')
        ->and($state->mode())->toBe('domain')
        ->and($state->url())->toBe('https://'.ConfigFixture::DOMAIN)
        ->and($settings->acmeEmail())->toBe('ops@example.com')
        ->and($settings->edgeSubnet())->not->toBeNull()
        ->and($settings->port())->toBe(8080)
        ->and(ComposeFile::services($this->config->instance->directory))->toContain('edge')
        ->and($app['APP_URL'])->toBe('https://'.ConfigFixture::DOMAIN)
        ->and($app['TRUSTED_PROXIES'])->toBe(EdgeNetwork::edgeAddress((string) $settings->edgeSubnet()))
        ->and($up)->toHaveCount(1)
        ->and($up[0])->toMatchArray(['services' => [], 'removeOrphans' => true])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('url_changed')
        ->and(collect($state->history())->last()['event'])->toBe('config_changed');
});

it('journals snapshot, preflight, apply, verify and audit, archives the journal and audits through a one-off', function (): void {
    [$code] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    $journal = $this->config->archivedJournal();
    $audit = $this->config->instance->oneOffCalls('instance:config-changed');
    expect($code)->toBe(0)
        ->and($this->config->openJournal())->toBeNull()
        ->and($journal->outcome()?->value)->toBe('ok')
        ->and(ConfigFixture::phases($journal))->toBe(['snapshot:completed', 'preflight:completed', 'apply:completed', 'verify:completed', 'audit:completed'])
        ->and($journal->context())->toMatchArray(['key' => 'domain', 'value' => ConfigFixture::DOMAIN])
        ->and($journal->phaseData('snapshot')['previous']['mode'])->toBe('local')
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]['command'])->toContain('--key=domain', '--old=', '--new='.ConfigFixture::DOMAIN, '--old-url=http://localhost:8080', '--new-url=https://'.ConfigFixture::DOMAIN, '--actor-uid='.posix_getuid())
        ->and($audit[0]['service'])->toBe('app');
});

it('changes nothing, without any Docker call, when the domain is set again', function (): void {
    $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    $before = file_get_contents($this->config->instance->directory->path('state.json'));
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('domain', strtoupper(ConfigFixture::DOMAIN));

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['data'])->toMatchArray(['key' => 'domain', 'from' => ConfigFixture::DOMAIN, 'to' => ConfigFixture::DOMAIN, 'mode' => 'domain'])
        ->and($this->config->methodsSince($mark))->toBe([])
        ->and(file_get_contents($this->config->instance->directory->path('state.json')))->toBe($before);
});

it('reverts to local mode with reverted: true when the edge gets no certificate', function (): void {
    $this->config->certificate = false;
    $compose = file_get_contents($this->config->instance->directory->path('compose.yaml'));
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    $journal = $this->config->archivedJournal();
    expect($code)->toBe(4, json_encode($json))
        ->and($json['status'])->toBe('failed')
        ->and($json['changed'])->toBeFalse()
        ->and($json['errors'][0]['code'])->toBe('certificate_failed')
        ->and($json['errors'][0]['message'])->toContain('the previous settings were restored')
        ->and($json['errors'][0]['hint'])->toContain('points at this host')->not->toContain('fsa install')
        ->and($json['data'])->toMatchArray(['reverted' => true, 'url' => 'http://localhost:8080', 'mode' => 'local'])
        ->and($json['data']['attempted'])->toBe(['url' => 'https://'.ConfigFixture::DOMAIN, 'mode' => 'domain'])
        ->and($this->config->state()->mode())->toBe('local')
        ->and(file_get_contents($this->config->instance->directory->path('compose.yaml')))->toBe($compose)
        ->and($this->config->callsSince($mark, 'composeUp'))->toHaveCount(2)
        ->and($this->config->openJournal())->toBeNull()
        ->and(ConfigFixture::phases($journal))->toBe(['snapshot:completed', 'preflight:completed', 'apply:completed', 'verify:failed', 'revert:completed'])
        ->and(collect($journal->events())->pluck('type')->all())->toContain('reverted')
        ->and($this->config->instance->oneOffCalls('instance:config-changed'))->toBe([]);
});

it('stops the instance and exits 8 with recovery steps when the revert fails too, then resumes with the same command', function (): void {
    $this->config->certificate = false;
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups === 2) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'port is already allocated'));
        }
    };

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(8, json_encode($json))
        ->and($json['status'])->toBe('rollback_failed')
        ->and($json['changed'])->toBeTrue()
        ->and($json['errors'][0]['code'])->toBe('revert_failed')
        ->and($json['data'])->toMatchArray(['reverted' => false, 'stopped' => true])
        ->and($json['data']['recovery'])->toHaveCount(3)
        ->and($json['data']['recovery'][2])->toContain('fsa config set domain '.ConfigFixture::DOMAIN)
        ->and($this->config->instance->docker->called('composeStop'))->toHaveCount(1)
        ->and($this->config->state()->mode())->toBe('local')
        ->and($this->config->openJournal()?->operation())->toBe('config');

    [, $status] = $this->config->instance->run('fsa:status');
    expect(collect($status['warnings'])->firstWhere('code', 'operation_unfinished')['hint'])->toContain('fsa config set domain '.ConfigFixture::DOMAIN);

    [$other, $refused] = $this->config->set('port', '8090', ['--yes' => true]);
    expect($other)->toBe(9)->and($refused['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($refused['errors'][0]['hint'])->toContain('fsa config set domain '.ConfigFixture::DOMAIN);

    $this->config->certificate = true;
    [$resumed, $done] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($resumed)->toBe(0, json_encode($done))
        ->and($done['data'])->toMatchArray(['mode' => 'domain', 'previous' => ['url' => 'http://localhost:8080', 'mode' => 'local']])
        ->and($this->config->openJournal())->toBeNull()
        ->and(collect($this->config->archivedJournals())->contains(fn ($record): bool => collect($record->events())->pluck('type')->contains('resumed')))->toBeTrue();
});

it('needs --yes for a URL change and lists the organizations with SSO and the links that keep the old address (exit 6)', function (): void {
    $this->config->instance->oneOffs['instance:url-impact'] = InstanceCommandFixture::oneOffJson(['status' => 'ok', 'data' => [
        'sso_organizations' => [['organization' => ['id' => 4, 'name' => 'Acme SSO', 'slug' => 'acme-sso', 'secret' => 'x'], 'provider' => ['type' => 'saml', 'name' => 'Entra', 'status' => 'active'], 'follows_url' => ['entity_id', 'acs_url']]],
        'links' => ['invitations' => 2, 'document_links' => 1, 'login_links' => 0, 'data_exports' => 0],
    ]]);
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN);

    expect($code)->toBe(6)
        ->and($json['status'])->toBe('aborted')
        ->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['errors'][0]['message'])->toContain('Acme SSO (SAML SSO)')->toContain('entity id and acs url')->toContain('2 invitations, 1 document links')
        ->and($json['data']['impact']['sso_organizations'][0]['organization'])->toBe(['id' => 4, 'name' => 'Acme SSO', 'slug' => 'acme-sso'])
        ->and($json['data']['url'])->toBe(['from' => 'http://localhost:8080', 'to' => 'https://'.ConfigFixture::DOMAIN])
        ->and($this->config->methodsSince($mark))->toBe(['composeRun'])
        ->and($this->config->state()->mode())->toBe('local')
        ->and($this->config->archivedJournals())->toBe([]);

    [$confirmed, $done] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    expect($confirmed)->toBe(0)
        ->and($done['data']['impact']['sso_organizations'])->toHaveCount(1)
        ->and(collect($done['warnings'])->pluck('code')->all())->toContain('sso_urls_changed');
});

it('still asks when the impact cannot be read, and says so', function (): void {
    $this->config->instance->oneOffs['instance:url-impact'] = new ProcessOutcome(1, '', 'SQLSTATE[08006] could not connect');

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN);

    expect($code)->toBe(6)
        ->and($json['data']['impact']['known'])->toBeFalse()
        ->and($json['errors'][0]['message'])->toContain('could not be read');
});

it('refuses invalid domains and foreign options with exit 2 before any Docker call', function (string $value, array $options, string $error): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('domain', $value, ['--yes' => true, ...$options]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($this->config->methodsSince($mark))->toBe([]);
})->with([
    'IP address' => ['203.0.113.10', [], 'invalid_value'],
    'no dot' => ['intranet', [], 'invalid_value'],
    'injection' => ['forms.example.com"; respond', [], 'invalid_value'],
    'bad ACME e-mail' => [ConfigFixture::DOMAIN, ['--acme-email' => 'not-an-email'], 'invalid_value'],
    'staging and CA' => [ConfigFixture::DOMAIN, ['--acme-staging' => true, '--acme-ca' => 'https://ca.example.com/dir'], 'invalid_flag'],
]);

it('refuses a domain that does not resolve to this host with exit 3, nothing changed', function (): void {
    $this->config->instance->probes->dns[ConfigFixture::DOMAIN] = ['a' => ['192.0.2.55'], 'aaaa' => []];
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('doctor_failed')
        ->and(collect($json['data']['checks'])->firstWhere('key', 'dns')['status'])->toBe('fail')
        ->and($this->config->callsSince($mark, 'composeUp'))->toBe([])
        ->and($this->config->state()->mode())->toBe('local')
        ->and($this->config->openJournal())->toBeNull();
});

it('refuses with exit 3 when ports 80 and 443 are taken on the host', function (): void {
    $this->config->takenHost = [443];

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(3)
        ->and(collect($json['data']['checks'])->firstWhere('key', 'ports')['details']['taken'])->toBe([443]);
});

it('changes the domain of a domain-mode instance, keeping its edge subnet and ACME inputs', function (): void {
    $this->config->instance->probes->dns['other.example.com'] = ['a' => [ConfigFixture::HOST_ADDRESS], 'aaaa' => []];
    $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true, '--acme-staging' => true]);
    $subnet = $this->config->state()->setting('edge_subnet');

    [$code, $json] = $this->config->set('domain', 'other.example.com', ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['from' => ConfigFixture::DOMAIN, 'to' => 'other.example.com', 'url' => 'https://other.example.com'])
        ->and($json['data']['services']['added'])->toBe([])
        ->and($json['data']['services']['recreated'])->toContain('edge')
        ->and($this->config->state()->setting('edge_subnet'))->toBe($subnet)
        ->and(InstanceSettings::of($this->config->state())->acmeCa())->toBe(InstanceSettings::ACME_STAGING);
});

it('writes a custom ACME CA root next to the Caddyfile and restores the previous one on revert', function (): void {
    $pem = ConfigFixture::pem();
    $file = TemporaryInstance::path().'.pem';
    file_put_contents($file, $pem);

    [$code] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true, '--acme-ca' => 'https://ca.example.com/dir', '--acme-ca-root-file' => $file]);

    expect($code)->toBe(0)
        ->and($this->config->instance->directory->read('edge/acme-ca-root.pem'))->toBe($pem)
        ->and($this->config->state()->setting('acme_ca_root'))->toBe(hash('sha256', $pem));

    $other = ConfigFixture::pem();
    file_put_contents($file, $other);
    $this->config->certificateFailures = 1;
    [$failed, $json] = $this->config->set('acme-ca', 'https://ca2.example.com/dir', ['--yes' => true, '--acme-ca-root-file' => $file]);

    expect($failed)->toBe(4, json_encode($json))
        ->and($json['data']['reverted'])->toBeTrue()
        ->and($this->config->instance->directory->read('edge/acme-ca-root.pem'))->toBe($pem)
        ->and(InstanceSettings::of($this->config->state())->acmeCa())->toBe('https://ca.example.com/dir');
    unlink($file);
});
