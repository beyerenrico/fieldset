<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11 (R32, ADR 0077): `fsa config set mode local` from domain and proxy mode, the transitions it refuses, and
 * `acme-email` / `acme-ca` in domain mode.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

it('returns from domain mode to local mode: the edge goes (orphan removal), its subnet and ACME inputs stay', function (): void {
    $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true, '--acme-email' => 'ops@example.com']);
    $subnet = $this->config->state()->setting('edge_subnet');
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('mode', 'local', ['--yes' => true]);

    $state = $this->config->state();
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['key' => 'mode', 'from' => 'domain', 'to' => 'local', 'url' => 'http://localhost:8080', 'mode' => 'local'])
        ->and($json['data']['services'])->toBe(['recreated' => ['app', 'queue', 'scheduler', 'mailpit'], 'added' => [], 'removed' => ['edge']])
        ->and($this->config->callsSince($mark, 'composeUp')[0])->toMatchArray(['removeOrphans' => true])
        ->and(ComposeFile::services($this->config->instance->directory))->not->toContain('edge')
        ->and($state->setting('domain'))->toBeNull()
        ->and($state->setting('edge_subnet'))->toBe($subnet)
        ->and($state->setting('acme_email'))->toBe('ops@example.com')
        ->and(InstanceSettings::of($state)->mailFromAddress())->toBe('forms@localhost')
        ->and($this->config->instance->directory->exists('env/edge.env'))->toBeFalse();
});

it('picks the next free ports when the remembered ones are taken now, and warns', function (): void {
    $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    $this->config->takenLoopback = [8080, 8025];

    [$code, $json] = $this->config->set('mode', 'local', ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['url'])->toBe('http://localhost:8081')
        ->and($this->config->state()->setting('mail_ui_port'))->toBe(8026)
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('port_changed');
});

it('returns from proxy mode to local mode, dropping the proxy settings and the shared network', function (): void {
    $config = new ConfigFixture(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture): void {
        $id = str_repeat('7', 64);
        $fixture->docker->containers[$id] = new ContainerInfo($id, 'traefik-1', 'running', null, null, 'traefik:v3.7.15');
        $fixture->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], [new NetworkEndpoint($id, 'traefik-1', '172.20.0.5')]));
    });

    [$code, $json] = $config->set('mode', 'local', ['--yes' => true]);

    $compose = ComposeFile::document($config->instance->directory);
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['from' => 'proxy', 'url' => 'http://localhost:8080'])
        ->and($config->state()->setting('proxy'))->toBeNull()
        ->and($config->state()->setting('trusted_proxies'))->toBeNull()
        ->and($compose['networks'])->not->toHaveKey('proxy')
        ->and($compose['services']['app']['ports'][0]['published'])->toBe('8080');
});

it('changes nothing in local mode and refuses the other directions with exit 2', function (string $value, int $exit, ?string $error): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('mode', $value, ['--yes' => true]);

    expect($code)->toBe($exit)->and($this->config->methodsSince($mark))->toBe([]);
    if ($error !== null) {
        expect($json['errors'][0]['code'])->toBe($error);
    }
})->with([
    'local again' => ['local', 0, null],
    'domain' => ['domain', 2, 'use_another_key'],
    'proxy' => ['proxy', 2, 'mode_not_settable'],
    'nonsense' => ['tunnel', 2, 'invalid_value'],
]);

it('changes the ACME contact and directory in domain mode; staging to production drops nothing it should keep', function (): void {
    $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true, '--acme-staging' => true]);

    [$email, $emailJson] = $this->config->set('acme-email', 'Admin@Example.com');
    [$ca, $caJson] = $this->config->set('acme-ca', 'production');
    [$none] = $this->config->set('acme-email', 'none');

    expect($email)->toBe(0, json_encode($emailJson))
        ->and($emailJson['data'])->toMatchArray(['from' => null, 'to' => 'admin@example.com'])
        ->and($emailJson['data']['services']['recreated'])->toBe(['edge'])
        ->and($ca)->toBe(0)
        ->and($caJson['data'])->toMatchArray(['from' => InstanceSettings::ACME_STAGING, 'to' => InstanceSettings::ACME_PRODUCTION])
        ->and($this->config->state()->setting('acme_ca'))->toBeNull()
        ->and($none)->toBe(0)
        ->and($this->config->state()->setting('acme_email'))->toBeNull()
        ->and(file_get_contents($this->config->instance->directory->path('edge/Caddyfile')))->not->toContain('email');
});

it('refuses ACME keys outside domain mode, ACME options on other keys and bad directories', function (): void {
    [$local, $localJson] = $this->config->set('acme-ca', 'staging');
    [$option, $optionJson] = $this->config->set('port', '8090', ['--yes' => true, '--acme-email' => 'ops@example.com']);
    $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    [$bad, $badJson] = $this->config->set('acme-ca', 'http://insecure.example.com/dir');
    [$mixed, $mixedJson] = $this->config->set('acme-ca', 'staging', ['--acme-email' => 'ops@example.com']);

    expect($local)->toBe(2)->and($localJson['errors'][0]['code'])->toBe('not_in_this_mode')
        ->and($option)->toBe(2)->and($optionJson['errors'][0]['code'])->toBe('invalid_flag')
        ->and($bad)->toBe(2)->and($badJson['errors'][0]['code'])->toBe('invalid_value')
        ->and($mixed)->toBe(2)->and($mixedJson['errors'][0]['code'])->toBe('invalid_flag');
});
