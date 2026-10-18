<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\ScriptedCommandRunner;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U8 (F1, R10, R16, AE5; ADR 0075): `fsa install --domain` against scripted Docker: DNS and ports 80/443 first,
 * the edge in the stack, the smoke test waiting for the certificate, exit 4 when none comes. The real thing:
 * tests/Install/Journeys/J02DomainModeTest.php (Pebble).
 */

const DOMAIN_FLAGS = ['--domain' => 'forms.example.com'];

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->probes->dns['forms.example.com'] = ['a' => ['8.8.4.4'], 'aaaa' => []];
    $this->fixture->facts(['lanAddresses' => ['8.8.4.4']]);
    $this->takenHostPorts = [];
    $this->privileged = false;
    $this->https = null;
    $this->fixture->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => domainRun($this, $spec));
});

afterEach(fn () => TemporaryInstance::cleanup());

/** Port probes, the smoke test through the networks and the edge, like a healthy host. */
function domainRun(object $test, ContainerSpec $spec): ?ProcessOutcome
{
    if ($spec->entrypoint === 'true') {
        [$ip, $port] = explode(':', $spec->ports[0] ?? '');

        return match (true) {
            $ip === '0.0.0.0' && $test->privileged => new ProcessOutcome(125, '', 'docker: Error response from daemon: driver failed programming external connectivity: error while calling RootlessKit PortManager.AddPort(): cannot expose privileged port 80'),
            $ip === '0.0.0.0' && in_array((int) $port, $test->takenHostPorts, true) => new ProcessOutcome(125, '', "Bind for 0.0.0.0:{$port} failed: port is already allocated"),
            default => new ProcessOutcome(0),
        };
    }
    if ($spec->entrypoint !== 'curl') {
        return null;
    }
    $url = $spec->command[array_key_last($spec->command)] ?? '';

    return match (true) {
        str_starts_with($url, 'https://forms.example.com') => $test->https ?? new ProcessOutcome(0, '200'),
        str_starts_with($url, 'http://forms.example.com') => new ProcessOutcome(0, '308 https://forms.example.com/up'),
        default => new ProcessOutcome(0, "200 http://app:8080/up\n"),
    };
}

function installDomain(InstanceCommandFixture $fixture, array $flags = []): array
{
    return $fixture->run('fsa:install', InstanceCommandFixture::installFlags([...DOMAIN_FLAGS, ...$flags]));
}

/** A self-signed CA certificate, as a private ACME directory's HTTPS root would be. */
function domainTestPem(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test ACME Root'], $key), null, $key, 30);
    openssl_x509_export($certificate, $pem);

    return $pem;
}

function writePem(string $contents): string
{
    $path = TemporaryInstance::path().'-root.pem';
    file_put_contents($path, $contents);

    return $path;
}

it('installs in domain mode: https URL, the edge in the stack, its subnet free of the host\'s networks', function (): void {
    $this->fixture->docker->subnets = ['172.17.0.0/16', '10.213.0.0/17']; // half of the edge pool is taken

    [$code, $json] = installDomain($this->fixture);
    $state = $this->fixture->state();
    $settings = InstanceSettings::of($state);
    $compose = ComposeFile::document($this->fixture->directory);
    $env = new EnvFiles($this->fixture->directory);
    $history = new OperationJournal($this->fixture->directory)->history()[0];

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['url' => 'https://forms.example.com', 'mode' => 'domain', 'port' => null])
        ->and($json['data']['mail'])->toBe(['mode' => 'capture', 'catcher_url' => 'https://forms.example.com/_mail/'])
        ->and($state->mode())->toBe('domain')
        ->and($state->url())->toBe('https://forms.example.com')
        ->and($state->setting('domain'))->toBe('forms.example.com')
        ->and($state->setting('port'))->toBeNull()
        ->and(EdgeNetwork::overlaps((string) $settings->edgeSubnet(), '10.213.128.0/17'))->toBeTrue()
        ->and(array_any($this->fixture->docker->subnets, fn (string $taken): bool => EdgeNetwork::overlaps($taken, (string) $settings->edgeSubnet())))->toBeFalse()
        ->and(array_keys($compose['services']))->toContain('edge')
        ->and($env->read('app')['TRUSTED_PROXIES'])->toBe(EdgeNetwork::edgeAddress((string) $settings->edgeSubnet()))
        ->and($this->fixture->directory->read('edge/Caddyfile'))->toContain('"forms.example.com" {')
        ->and(json_decode((string) $this->fixture->directory->read('runtime/runtime.json'), true)['instance_url'])->toBe('https://forms.example.com')
        ->and(array_column($this->fixture->docker->called('pullImage'), 'reference'))->toContain($state->digests()['caddy'])
        ->and(array_column($history->phases(), 'name'))->toBe(['doctor', 'secrets', 'state', 'pull', 'up', 'migrate', 'app_install', 'start', 'smoke', 'done'])
        ->and($history->phaseData('doctor'))->toMatchArray(['mode' => 'domain', 'url' => 'https://forms.example.com', 'proxy' => null, 'port' => null])
        ->and($history->phaseData('doctor')['edge'])->toBe(['domain' => 'forms.example.com', 'edge_subnet' => $settings->edgeSubnet()])
        ->and($state->history()[0])->toMatchArray(['event' => 'installed']);
});

it('probes 80 and 443 on every host address, not the loopback app and catcher ports', function (): void {
    installDomain($this->fixture);
    $probes = array_map(static fn (ContainerSpec $spec): string => $spec->ports[0], $this->fixture->runSpecs('true'));

    expect($probes)->toBe(['0.0.0.0:80:8080', '0.0.0.0:443:8080']);
});

it('asks the edge for HTTPS until it holds a certificate, then checks the redirect, on the edge network', function (): void {
    installDomain($this->fixture);
    $state = $this->fixture->state();
    $address = EdgeNetwork::edgeAddress((string) InstanceSettings::of($state)->edgeSubnet());
    [, , $https, $http] = $this->fixture->runSpecs('curl');

    expect($https->network)->toBe($state->project().'_edge')
        ->and($https->labels)->toBe(Labels::forInstance($state->uuid(), 'smoke'))
        ->and($https->command)->toContain('--resolve', "forms.example.com:443:{$address}", '--insecure', 'https://forms.example.com/up')
        ->and($https->command)->not->toContain('--retry') // one attempt per container; SmokeTest loops (ADR 0078)
        ->and($http->network)->toBe($state->project().'_edge')
        ->and($http->command)->toContain("forms.example.com:80:{$address}", 'http://forms.example.com/up')
        ->and($http->command)->not->toContain('--insecure');
});

it('sets the ACME contact, staging and a custom CA root from the flags', function (): void {
    $pem = domainTestPem();
    [$code, $json] = installDomain($this->fixture, ['--acme-email' => 'Ops@Example.com', '--acme-staging' => true, '--acme-ca-root-file' => writePem($pem)]);
    $state = $this->fixture->state();
    $caddyfile = (string) $this->fixture->directory->read('edge/Caddyfile');

    expect($code)->toBe(0, json_encode($json))
        ->and($state->setting('acme_email'))->toBe('ops@example.com')
        ->and($state->setting('acme_ca'))->toBe(InstanceSettings::ACME_STAGING)
        ->and($state->setting('acme_ca_root'))->toBe(hash('sha256', $this->fixture->directory->read('edge/acme-ca-root.pem') ?? ''))
        ->and(fileperms($this->fixture->directory->path('edge/acme-ca-root.pem')) & 0777)->toBe(0644)
        ->and($caddyfile)->toContain('email "ops@example.com"', 'acme_ca "'.InstanceSettings::ACME_STAGING.'"', 'acme_ca_root "/etc/caddy/acme-ca-root.pem"');
});

it('refuses inconsistent or malformed domain-mode flags with exit 2 before anything runs', function (array $flags, string $error): void {
    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags($flags));

    expect($code)->toBe(2, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($this->fixture->docker->called('pullImage'))->toBe([])
        ->and($this->fixture->directory->hasState())->toBeFalse();
})->with([
    'acme flag without --domain' => [['--acme-email' => 'ops@example.com'], 'domain_required'],
    'staging and a directory' => [[...DOMAIN_FLAGS, '--acme-staging' => true, '--acme-ca' => 'https://ca.example.com/dir'], 'invalid_flag'],
    'http directory' => [[...DOMAIN_FLAGS, '--acme-ca' => 'http://ca.example.com/dir'], 'invalid_flag'],
    'port with --domain' => [[...DOMAIN_FLAGS, '--port' => '8081'], 'invalid_flag'],
    'ip as domain' => [['--domain' => '203.0.113.10'], 'invalid_flag'],
    'malformed email' => [[...DOMAIN_FLAGS, '--acme-email' => 'ops"@example.com'], 'invalid_flag'],
]);

it('refuses a CA root file that is not PEM certificates', function (string $contents): void {
    [$code, $json] = installDomain($this->fixture, ['--acme-ca-root-file' => writePem($contents)]);

    expect($code)->toBe(2)->and($json['errors'][0]['message'])->toContain('PEM');
})->with([
    'private key' => ["-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n"],
    'certificate with trailing junk' => [fn () => domainTestPem()."\n{\$X}\n"],
    'garbage in a certificate block' => ["-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"],
    'empty' => [''],
]);

it('refuses a CA root path that is no readable file', function (): void {
    [$code, $json] = installDomain($this->fixture, ['--acme-ca-root-file' => TemporaryInstance::path().'-missing.pem']);

    expect($code)->toBe(2)->and($json['errors'][0]['message'])->toContain('readable PEM file');
});

it('asks for --acme-ca-root-file again when a resume reaches the state phase without the written root', function (): void {
    $root = writePem(domainTestPem());
    $env = new EnvFiles($this->fixture->directory);
    $env->write('app', ['APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32))]); // the secrets phase fails

    [$failed] = installDomain($this->fixture, ['--acme-ca-root-file' => $root]);
    $env->remove('app');
    [$missing, $missingJson] = installDomain($this->fixture);
    [$resumed, $resumedJson] = installDomain($this->fixture, ['--acme-ca-root-file' => $root]);
    [$again, $againJson] = installDomain($this->fixture);

    expect($failed)->toBe(3)
        ->and($missing)->toBe(2)
        ->and($missingJson['errors'][0]['code'])->toBe('acme_ca_root_missing')
        ->and($resumed)->toBe(0, json_encode($resumedJson))
        ->and($this->fixture->state()->setting('acme_ca_root'))->toBe(hash('sha256', (string) $this->fixture->directory->read('edge/acme-ca-root.pem')))
        ->and($again)->toBe(0)
        ->and($againJson['changed'])->toBeFalse();
});

it('fails the preflight with exit 3 when port 80 or 443 is in use, after the DNS check', function (): void {
    $this->takenHostPorts = [443];

    [$code, $json] = installDomain($this->fixture);
    $checks = collect($json['data']['checks']);

    expect($code)->toBe(3)
        ->and($checks->firstWhere('key', 'ports')['summary'])->toContain('443')->toContain('in use')
        ->and($checks->firstWhere('key', 'ports')['details']['taken'])->toBe([443])
        ->and($checks->firstWhere('key', 'dns')['status'])->toBe('ok')
        ->and($this->fixture->docker->called('pullImage'))->toBe([])
        ->and($this->fixture->directory->hasState())->toBeFalse();
});

it('names rootless Docker and the sysctl when the engine may not publish privileged ports', function (): void {
    $this->privileged = true;
    $this->fixture->docker->info = new DockerInfo('29.9.0', 'Ubuntu 24.04', 'x86_64', 4 * 1024 ** 3, 2, true);

    [$code, $json] = installDomain($this->fixture);
    $ports = collect($json['data']['checks'])->firstWhere('key', 'ports');

    expect($code)->toBe(3)
        ->and($ports['summary'])->toContain('Rootless Docker cannot publish port 80')
        ->and($ports['hint'])->toContain('net.ipv4.ip_unprivileged_port_start=80')
        ->and($ports['details']['rootless'])->toBeTrue();
});

it('exits 4 with the edge log when no certificate comes, keeps its storage, and resumes at the smoke test', function (): void {
    $this->https = new ProcessOutcome(35, '', 'curl: (35) OpenSSL/3.0: error:0A000438:SSL routines::tlsv1 alert internal error');
    $this->fixture->docker->logs = 'edge-1 | {"level":"error","msg":"could not get certificate from issuer"}';

    [$code, $json] = installDomain($this->fixture);
    $journal = new OperationJournal($this->fixture->directory)->current();
    $this->https = null;
    [$resumed, $again] = installDomain($this->fixture);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('certificate_failed')
        ->and($json['errors'][0]['retryable'])->toBeTrue()
        ->and($json['errors'][0]['hint'])->toContain('keeps the certificate storage')
        ->and($json['data']['logs'])->toContain('could not get certificate')
        ->and($journal?->lastStartedPhase())->toBe('smoke')
        ->and($this->fixture->docker->called('removeVolume'))->toBe([])
        ->and($this->fixture->docker->called('composeDown'))->toBe([])
        ->and($resumed)->toBe(0, json_encode($again));
});

it('changes nothing on a repeated install with the same flags, and wants config set for another ACME contact', function (): void {
    installDomain($this->fixture, ['--acme-email' => 'ops@example.com']);

    [$same, $sameJson] = installDomain($this->fixture, ['--acme-email' => 'ops@example.com']);
    [$other, $otherJson] = installDomain($this->fixture, ['--acme-email' => 'someone@example.com']);
    [$domain, $domainJson] = installDomain($this->fixture, ['--domain' => 'other.example.com']);

    expect($same)->toBe(0)->and($sameJson['changed'])->toBeFalse()
        ->and($sameJson['data']['url'])->toBe('https://forms.example.com')
        ->and($other)->toBe(2)->and($otherJson['errors'][0]['message'])->toContain('--acme-email')
        ->and($domain)->toBe(2)->and($domainJson['errors'][0]['code'])->toBe('use_config_set');
});

it('refuses another domain on a resume once the doctor phase has applied one', function (): void {
    $this->fixture->smokeOutcome = null;
    $this->https = new ProcessOutcome(35, '', 'curl: (35) alert');
    installDomain($this->fixture);

    [$code, $json] = installDomain($this->fixture, ['--domain' => 'other.example.com']);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('flag_already_applied');
});

it('reports domain mode in status and serves 80/443 from its own edge in doctor', function (): void {
    installDomain($this->fixture);
    $state = $this->fixture->state();
    $this->fixture->withRunningStack();
    $this->fixture->docker->withRunningContainer(str_repeat('e', 12), '2026-10-18T10:00:00Z', [
        Labels::COMPOSE_PROJECT => $state->project(), Labels::COMPOSE_SERVICE => 'edge', Labels::INSTANCE => $state->uuid(), Labels::ROLE => 'edge',
    ], $state->project().'-edge-1');
    $probes = count($this->fixture->runSpecs('true'));

    [, $status] = $this->fixture->run('fsa:status');
    [$doctorCode, $doctor] = $this->fixture->run('fsa:doctor');

    expect($status['data'])->toMatchArray(['mode' => 'domain', 'url' => 'https://forms.example.com', 'port' => null])
        ->and($status['data']['mail']['catcher_url'])->toBe('https://forms.example.com/_mail/')
        ->and($doctorCode)->toBe(0, json_encode($doctor))
        ->and(collect($doctor['data']['checks'])->firstWhere('key', 'ports')['summary'])->toContain('served by this instance')
        ->and($this->fixture->runSpecs('true'))->toHaveCount($probes);
});

it('warns about the CA\'s duplicate-certificate limit before and after uninstalling a domain instance', function (): void {
    installDomain($this->fixture);
    $uuid = $this->fixture->state()->uuid();
    $this->fixture->docker->withResource(new DockerResource(DockerResource::VOLUME, 'proj_edge-data', Labels::forInstance($uuid)));

    [$asked, $prompt] = $this->fixture->run('fsa:uninstall');
    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    expect($asked)->toBe(6)
        ->and($prompt['errors'][0]['message'])->toContain('required: The certificates for forms.example.com')
        ->and($code)->toBe(0, json_encode($json))
        ->and($json['data']['removed']['volumes'])->toBe(['proj_edge-data'])
        ->and(collect($json['warnings'])->firstWhere('code', 'certificates_removed')['hint'])->toContain('--acme-staging');
});

it('lists the subnets of every Docker network through the CLI', function (): void {
    $runner = (new ScriptedCommandRunner)->push(new ProcessOutcome(0, "n1\nn2\n"), new ProcessOutcome(0, "172.17.0.0/16 \n10.213.4.32/28 fd00::/64 \n"));
    $docker = new CliDockerGateway($runner);

    expect($docker->networkSubnets())->toBe(['172.17.0.0/16', '10.213.4.32/28', 'fd00::/64'])
        ->and($runner->runs[0]['command'])->toBe(['docker', 'network', 'ls', '--quiet', '--no-trunc'])
        ->and($runner->runs[1]['command'])->toBe(['docker', 'network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Subnet}} {{end}}', 'n1', 'n2'])
        ->and(new CliDockerGateway((new ScriptedCommandRunner)->push(new ProcessOutcome(0, '')))->networkSubnets())->toBe([]);
});
