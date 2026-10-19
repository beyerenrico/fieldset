<?php

declare(strict_types=1);

use App\Domain\Instance\Config\Appliers\AcmeSettings;
use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11 (ADR 0077): the less travelled paths of `fsa config`: SMTP mail in `config show`, the request's command
 * line, ACME settings that drop a custom root, proxy lookups that fail, an unexpected exception during apply, and a
 * stop that fails after a failed revert.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('shows SMTP mail with its server as an active outbound contact and the SMTP secrets by name only', function (): void {
    $config = new ConfigFixture;
    $directory = $config->instance->directory;
    $directory->saveState($directory->state()->withSettings(['mail' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 465, 'smtp_scheme' => 'smtps']));
    $env = new EnvFiles($directory);
    $env->write('app', [...$env->read('app'), 'MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => 'smtp-secret-value']);

    [$code, $json] = $config->show();

    $contacts = collect($json['data']['outbound_contacts']['features'])->keyBy('feature');
    expect($code)->toBe(0)
        ->and($json['data']['settings']['mail'])->toMatchArray(['mode' => 'smtp', 'catcher_url' => null, 'smtp' => ['host' => 'smtp.example.com', 'port' => 465, 'scheme' => 'smtps', 'encryption' => 'tls']])
        ->and($json['data']['settings']['mail_ui_port'])->toBeNull()
        ->and($json['data']['secrets'])->toMatchArray(['smtp_username' => '[redacted]', 'smtp_password' => '[redacted]'])
        ->and(json_encode($json))->not->toContain('smtp-secret-value')
        ->and($contacts['smtp'])->toMatchArray(['active' => true, 'contacts' => ['smtp.example.com']]);
});

it('refuses config show without a state', function (): void {
    $fixture = new InstanceCommandFixture;

    [$code, $json] = $fixture->run('fsa:config', ['action' => 'show']);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('not_installed');
});

it('names the command to repeat with every option, the CA root by reference', function (): void {
    $request = new ConfigRequest('domain', 'forms.example.com', 'ops@example.com', 'https://ca.example.com/dir', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");

    expect($request->commandLine())->toBe('fsa config set domain forms.example.com --acme-email ops@example.com --acme-ca https://ca.example.com/dir --acme-ca-root-file <the same file> --yes')
        ->and($request->toContext()['acme_ca_root'])->toHaveLength(64)
        ->and($request->sameAs([...$request->toContext(), 'value' => 'other.example.com']))->toBeFalse();
});

it('drops a custom CA root when the directory becomes Let\'s Encrypt', function (string $ca): void {
    $settings = AcmeSettings::from(new ConfigRequest('acme-ca', $ca, acmeCa: $ca), TemporaryInstance::directory());

    expect($settings->settings)->toHaveKey('acme_ca_root')->and($settings->settings['acme_ca_root'])->toBeNull()->and($settings->caRoot)->toBeNull();
})->with(['production', 'staging', InstanceSettings::ACME_PRODUCTION]);

it('refuses the port on a shared proxy network, and a missing proxy network or gateway is exit 3', function (): void {
    $config = new ConfigFixture(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture): void {
        $id = str_repeat('7', 64);
        $fixture->docker->containers[$id] = new ContainerInfo($id, 'traefik-1', 'running', null, null, 'traefik:v3');
        $fixture->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], [new NetworkEndpoint($id, 'traefik-1', '172.20.0.5')]));
    });

    [$port, $portJson] = $config->set('port', '9000', ['--yes' => true]);
    $config->instance->docker->networkDetails = [];
    [$missing, $missingJson] = $config->set('trusted-proxy', 'auto');

    expect($port)->toBe(2)->and($portJson['errors'][0]['hint'])->toContain('shared proxy network')
        ->and($missing)->toBe(3)->and($missingJson['errors'][0]['code'])->toBe('proxy_network_missing');

    $gateway = new ConfigFixture(['--behind-proxy' => 'port', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture): void {
        $fixture->docker->networkResponder = fn (string $name): ?NetworkInfo => str_ends_with($name, '_default') ? new NetworkInfo('n1', $name, false, ['172.30.0.0/16'], ['172.30.0.1']) : null;
    });
    $gateway->instance->docker->networkResponder = null;
    [$lost, $lostJson] = $gateway->set('trusted-proxy', 'auto');

    expect($lost)->toBe(3)->and($lostJson['errors'][0]['code'])->toBe('gateway_unknown')->and($lostJson['errors'][0]['hint'])->toContain('Start the instance');
});

it('reverts after an unexpected exception during apply and reports it as an internal error', function (): void {
    $config = new ConfigFixture;
    $ups = 0;
    $config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups === 1) {
            throw new RuntimeException('the engine went away');
        }
    };

    [$code, $json] = $config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('internal_error')
        ->and($json['data']['failure'])->toBe(['exception' => RuntimeException::class])
        ->and($json['data']['reverted'])->toBeTrue();
});

it('says so when stopping the instance fails after a failed revert', function (): void {
    $config = new ConfigFixture;
    $config->certificate = false;
    $ups = 0;
    $config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups === 2) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'no space left on device'));
        }
    };
    $config->instance->docker->onCall['composeStop'] = function (array $args): void {
        if (in_array('app', $args['services'], true)) { // the stop after the failed revert, not apply's worker stop
            throw new DockerCommandFailed('compose stop', new ProcessOutcome(1, '', 'daemon gone'));
        }
    };

    [$code, $json] = $config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(8)
        ->and($json['data']['stopped'])->toBeFalse()
        ->and($json['data']['recovery'][0])->toContain('stopping the services failed as well')
        ->and($json['data']['revert_failure']['code'])->toBe('services_unhealthy');
});

it('fails the domain preflight when the port probe itself errors', function (): void {
    $config = new ConfigFixture;
    $config->instance->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $spec->entrypoint === 'true' ? new ProcessOutcome(125, '', 'docker: Error response from daemon: no such image') : null);

    [$code, $json] = $config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(3)
        ->and(collect($json['data']['checks'])->firstWhere('key', 'ports')['summary'])->toContain('Cannot probe port 80');
});

it('removes a CA root the previous settings did not trust when the change reverts', function (): void {
    $config = new ConfigFixture;
    $config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    $file = TemporaryInstance::path().'.pem';
    file_put_contents($file, ConfigFixture::pem());
    $config->refuseCa = 'ca.example.com';

    [$code, $json] = $config->set('acme-ca', 'https://ca.example.com/dir', ['--acme-ca-root-file' => $file]);

    expect($code)->toBe(5, json_encode($json))
        ->and($config->instance->directory->exists('edge/acme-ca-root.pem'))->toBeFalse()
        ->and($config->state()->setting('acme_ca_root'))->toBeNull();
    unlink($file);
});

it('resumes a change killed during apply from the journal: the recorded target, no new port probe or plan', function (): void {
    $config = new ConfigFixture;
    $config->interruptedDuringApply(new ConfigRequest('port', '8090'));
    $config->takenLoopback = [8090]; // as if the killed attempt's app still held it
    $mark = $config->mark();

    [$code, $json] = $config->set('port', '8090', ['--yes' => true]);

    $probes = array_filter($config->callsSince($mark, 'runContainer'), fn (array $call): bool => $call['spec']->entrypoint === 'true');
    expect($code)->toBe(0, json_encode($json))
        ->and($probes)->toBe([])
        ->and($json['data']['previous'])->toBe(['url' => 'http://localhost:8080', 'mode' => 'local'])
        ->and($config->state()->setting('port'))->toBe(8090)
        ->and($config->openJournal())->toBeNull();
});

it('keeps the edge subnet a killed attempt recorded and does not choose one again', function (): void {
    $config = new ConfigFixture;
    $config->interruptedDuringApply(new ConfigRequest('domain', ConfigFixture::DOMAIN), fn ($after) => $after->withSetting('edge_subnet', '10.213.9.0/28'));
    $mark = $config->mark();

    [$code, $json] = $config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($config->state()->setting('edge_subnet'))->toBe('10.213.9.0/28')
        ->and($config->methodsSince($mark))->not->toContain('networkSubnets');
});

it('takes the resumed target from the journal before any engine lookup, so a stopped proxy does not block the resume', function (): void {
    $id = str_repeat('7', 64);
    $network = fn (string $address): NetworkInfo => new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], [new NetworkEndpoint($id, 'traefik-1', $address)]);
    $config = new ConfigFixture(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com'], function (InstanceCommandFixture $fixture) use ($id, $network): void {
        $fixture->docker->containers[$id] = new ContainerInfo($id, 'traefik-1', 'running', null, null, 'traefik:v3');
        $fixture->docker->withNetwork($network('172.20.0.5'));
    });
    $config->instance->docker->withNetwork($network('172.20.0.9')); // Traefik was recreated with a new address
    $config->interruptedDuringApply(new ConfigRequest('trusted-proxy', 'auto'));
    $config->instance->docker->networkDetails = []; // the proxy and its network are gone now
    $mark = $config->mark();

    [$code, $json] = $config->set('trusted-proxy', 'auto');

    expect($code)->toBe(0, json_encode($json))
        ->and($config->methodsSince($mark))->not->toContain('inspectNetwork')
        ->and($config->state()->setting('trusted_proxies'))->toBe('172.20.0.9');
});

it('keeps a verified change when the audit one-off cannot even start', function (): void {
    $config = new ConfigFixture;
    $config->instance->docker->respondToComposeRun(function (array $command): ?ProcessOutcome {
        if (($command[2] ?? null) === 'instance:config-changed') {
            throw new DockerUnavailable('The Docker daemon is not reachable: gone');
        }

        return null;
    });

    [$code, $json] = $config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('audit_failed')
        ->and($config->openJournal())->toBeNull();
});

it('treats an absent port as its default when comparing', function (): void {
    $before = TemporaryInstance::withState()->state();

    $change = new ConfigChange('port', $before, $before->withSetting('port', 8080), '8080', '8080');

    expect($change->unchanged())->toBeTrue()
        ->and(new ConfigChange('port', $before, $before->withSetting('port', 8081), '8080', '8081')->unchanged())->toBeFalse();
});
