<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\Services\EdgeService;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\FakeTunnel;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\LocalCaClient;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J6 (BUILD_PROMPT_V2 §11, R19, R20, R29, R61, R65, AE6, F3; plan U14; ADR 0082), with real Docker against the harness
 * release:
 *
 * - Local CA: install with --phone-https local-ca on this host's LAN address (the shim's best candidate, which Docker
 *   Desktop and Linux engines both publish on) → the certificate is issued for the IP and a client without SNI verifies
 *   it against the instance's root → port 80 serves only the trust page and the root (308 otherwise) → Chromium that
 *   trusts the CA (SPKI list, standing in for a phone) loads the Field App on https://<IP> and its service worker
 *   registers, the trust page is axe-clean at phone width → mutual exclusion with the tunnel (exit 2) → turning the
 *   local CA off deletes the CA key from the certificate volume.
 * - Quick tunnel with the fake tunnel (FIELDSET_TEST_TUNNEL, harness images only): organizations besides the demo need --yes
 *   (exit 6); another account on `password` refuses (exit 3); start rotates the demo passwords and reaches /app and
 *   /api while /platform, /admin and the trust page are 404; after its (shortened) lifetime the tunnel host answers 503,
 *   `fieldset status` reports it expired without changing anything, and the next mutating command removes it (AE6); renew
 *   and stop; local CA and domain next to a tunnel exit 2.
 *
 * Everything is named `fieldset-test-j06-…` and removed afterwards. Evidence goes to the milestone of FIELDSET_EVIDENCE_MILESTONE.
 */

const J06_TTL = 180;

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fieldsetJ06'] = new InstanceJourney($harness, $release, $harness->directory.'/j06');
        $GLOBALS['fieldsetJ06Tunnel'] = FakeTunnel::build($release->platformEnvironment());
        $GLOBALS['fieldsetJ06Lan'] = LocalCaClient::lanAddress() ?? throw new RuntimeException('J6 needs a private IPv4 LAN address on this host (FIELDSET_LAN_ADDRESSES is empty).');
    } catch (Throwable $exception) {
        $GLOBALS['fieldsetJ06Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fieldsetJ06'])) {
        InstanceJourney::cleanup($GLOBALS['fieldsetJ06']->work.'/fieldset-test-j06', $GLOBALS['fieldsetJ06Uuid'] ?? null);
    }
    if (isset($GLOBALS['fieldsetJ06Tunnel'])) {
        $GLOBALS['fieldsetJ06Tunnel']->remove();
    }
    foreach (['fieldsetJ06', 'fieldsetJ06Tunnel', 'fieldsetJ06Lan', 'fieldsetJ06Error', 'fieldsetJ06Uuid', 'fieldsetJ06Fingerprint', 'fieldsetJ06Hostname'] as $key) {
        unset($GLOBALS[$key]);
    }
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fieldsetJ06Error'])) {
        throw $GLOBALS['fieldsetJ06Error'];
    }
    $this->journey = $GLOBALS['fieldsetJ06'];
    $this->tunnel = $GLOBALS['fieldsetJ06Tunnel'];
    $this->lan = $GLOBALS['fieldsetJ06Lan'];
    $this->dir = $this->journey->work.'/fieldset-test-j06';
});

/** @return array<string, mixed> */
function j06State(string $dir): array
{
    return json_decode((string) @file_get_contents($dir.'/state.json'), true) ?: [];
}

/** @return array<string, mixed> */
function j06Runtime(string $dir): array
{
    return json_decode((string) @file_get_contents($dir.'/runtime/runtime.json'), true) ?: [];
}

/** @param  list<string>  $command */
function j06Docker(array $command, array $env = []): Process
{
    $process = new Process(['docker', ...$command], null, $env);
    $process->setTimeout(300);
    $process->run();

    return $process;
}

/** A container of the instance by its role, or ''. */
function j06Container(string $role): string
{
    return trim(j06Docker(['ps', '-aq', '--filter', 'label=app.fieldset.instance='.($GLOBALS['fieldsetJ06Uuid'] ?? ''), '--filter', "label=app.fieldset.role={$role}"])->getOutput());
}

/** @return array{status: int, headers: array<string, string>, body: string} a request through the fake tunnel */
function j06ThroughTunnel(string $dir, string $path, array $headers = []): array
{
    $state = j06State($dir);

    return FakeTunnel::request($state['project'], $state['digests']['app'], $state['settings']['tunnel_subnet'], $path, $GLOBALS['fieldsetJ06']->release->platformEnvironment(), $headers);
}

/**
 * `--secrets-file` for a command that hands out one-time secrets (demo passwords, sign-in links): they go to a file in
 * the workspace, never into stdout and the committed evidence.
 *
 * @return list<string>
 */
function j06SecretsFile(object $test, string $name): array
{
    return ['--secrets-file', $test->journey->work.'/j06-'.$name.'-secrets.json'];
}

/** @param  array<string, string>  $env */
function j06Run(object $test, array $arguments, string $evidence, array $env = []): array
{
    $process = $test->journey->fieldset($test->dir, [...$arguments, '--json'], $env);
    $json = InstanceJourney::json($process);
    $test->journey->evidence($evidence, ['command' => 'fieldset '.implode(' ', $arguments).' --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json]);

    return [$process, $json];
}

it('installs with --phone-https local-ca on the LAN address and prints the root fingerprint', function (): void {
    $flags = ['--org', 'J6 Org', '--owner-email', 'owner@example.test', '--locale', 'en', '--phone-https', 'local-ca', '--secrets-file', $this->journey->work.'/owner-link.json', '--json'];
    $started = microtime(true);
    $process = $this->journey->install($this->dir, $flags);
    $json = InstanceJourney::json($process);
    $GLOBALS['fieldsetJ06Uuid'] = InstanceJourney::uuid($this->dir);
    $root = (string) @file_get_contents($this->dir.'/runtime/local-ca-root.crt');
    $GLOBALS['fieldsetJ06Fingerprint'] = $json['data']['phone_https']['root_sha256'] ?? null;
    $this->journey->evidence('J06-install.json', ['command' => 'install.sh --dir <work>/fieldset-test-j06 --version '.ReleaseHarness::version().' '.implode(' ', array_slice($flags, 0, 8)).' --secrets-file <work>/owner-link.json --json', 'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $started, 1), 'stdout' => $json]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['url'])->toBe("https://{$this->lan}")
        ->and($json['data']['mode'])->toBe('local')
        ->and($json['data']['phone_https'])->toMatchArray(['mode' => 'local-ca', 'lan_address' => $this->lan, 'trust_url' => "http://{$this->lan}/phone-trust"])
        ->and($GLOBALS['fieldsetJ06Fingerprint'])->toBe(implode(':', str_split(strtoupper((string) openssl_x509_fingerprint($root, 'sha256')), 2)))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('local_ca_trust', 'lan_exposed', 'url_changed_phone_data')
        ->and(j06State($this->dir)['settings'])->toMatchArray(['phone_https' => 'local-ca', 'lan_address' => $this->lan])
        ->and(j06Runtime($this->dir)['instance_url'])->toBe("https://{$this->lan}")
        ->and($root)->toContain('BEGIN CERTIFICATE')->not->toContain('PRIVATE KEY');
});

it('issues the certificate for the IP, and a client without SNI completes the handshake against the root', function (): void {
    $handshake = LocalCaClient::handshake($this->lan, $this->dir.'/runtime/local-ca-root.crt');
    $published = trim(j06Docker(['port', j06Container('edge')])->getOutput());
    $this->journey->evidence('J06-certificate.json', ['san' => $handshake['san'], 'verified_without_sni' => $handshake['ok'], 'error' => $handshake['error'], 'chain_length' => count($handshake['chain']), 'edge_ports' => explode("\n", $published)]);

    expect($handshake['ok'])->toBeTrue($handshake['error'])
        ->and($handshake['san'])->toContain("IP Address:{$this->lan}")
        ->and(explode("\n", $published))->each->toContain($this->lan.':')
        ->and($published)->not->toContain('0.0.0.0');
});

it('serves only the trust page and the root certificate on port 80, everything else 308 to HTTPS', function (): void {
    $admin = LocalCaClient::http($this->lan, '/admin/login?x=1');
    $app = LocalCaClient::http($this->lan, '/app');
    $trust = LocalCaClient::http($this->lan, '/phone-trust');
    $root = LocalCaClient::http($this->lan, '/phone-trust/root.crt');
    $this->journey->evidence('J06-port-80.json', [
        'admin_login' => [$admin['status'], $admin['location'], ...($admin['status'] === 0 ? [$admin['body']] : [])], 'app' => [$app['status'], $app['location']],
        'trust_page' => ['status' => $trust['status'], 'hsts' => $trust['headers']['strict-transport-security'] ?? null, 'csp' => $trust['headers']['content-security-policy'] ?? null, 'set_cookie' => $trust['headers']['set-cookie'] ?? null],
        'root' => ['status' => $root['status'], 'type' => $root['headers']['content-type'] ?? null],
    ]);

    expect($admin['status'])->toBe(308)->and($admin['location'])->toBe("https://{$this->lan}/admin/login?x=1")
        ->and($app['status'])->toBe(308)
        ->and($trust['status'])->toBe(200)
        ->and($trust['body'])->toContain((string) $GLOBALS['fieldsetJ06Fingerprint'])
        ->and($trust['headers'])->not->toHaveKey('strict-transport-security')
        ->and($trust['headers'])->not->toHaveKey('set-cookie')
        ->and($trust['headers']['content-security-policy'] ?? '')->not->toContain('upgrade-insecure-requests')
        ->and($root['status'])->toBe(200)
        ->and($root['headers']['content-type'] ?? '')->toBe('application/x-x509-ca-cert')
        ->and(trim($root['body']))->toBe(trim((string) file_get_contents($this->dir.'/runtime/local-ca-root.crt')));
});

it('loads the Field App over HTTPS in a browser that trusts the CA, its service worker registers, and the trust page is axe-clean', function (): void {
    $handshake = LocalCaClient::handshake($this->lan, $this->dir.'/runtime/local-ca-root.crt');
    $spki = LocalCaClient::spki([(string) file_get_contents($this->dir.'/runtime/local-ca-root.crt'), ...$handshake['chain']]);
    $process = LocalCaClient::playwright([
        'FIELDSET_PHONE_TRUST_URL' => "http://{$this->lan}/phone-trust", 'FIELDSET_FIELD_APP_URL' => "https://{$this->lan}/app/",
        'FIELDSET_LOCAL_CA_FINGERPRINT' => (string) $GLOBALS['fieldsetJ06Fingerprint'], 'FIELDSET_LOCAL_CA_SPKI' => $spki,
    ]);
    $output = trim($process->getOutput()."\n".$process->getErrorOutput());
    $this->journey->evidence('J06-playwright.json', ['command' => 'playwright test --project=fieldset-instance (tests/e2e/fieldset-phone-trust.spec.ts)', 'exit_code' => $process->getExitCode(), 'output' => array_slice(explode("\n", $output), -25)]);

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->not->toContain('skipped');
});

it('reports the local CA in status and doctor, and refuses a tunnel next to it (R61)', function (): void {
    [$status, $statusJson] = j06Run($this, ['status'], 'J06-status-local-ca.json');
    [$doctor, $doctorJson] = j06Run($this, ['doctor', '--post'], 'J06-doctor-local-ca.json');
    [$tunnel, $tunnelJson] = j06Run($this, ['tunnel', 'start', '--yes'], 'J06-tunnel-next-to-local-ca.json', $this->tunnel->environment(J06_TTL));

    expect($status->getExitCode())->toBe(0, $status->getOutput())
        ->and($statusJson['data']['phone_https'])->toMatchArray(['mode' => 'local-ca', 'root_sha256' => $GLOBALS['fieldsetJ06Fingerprint'], 'lan_address_current' => true])
        ->and($statusJson['data']['services'])->toHaveKey('edge')
        ->and($doctor->getExitCode())->toBe(0, $doctor->getOutput())
        ->and(collect($doctorJson['data']['checks'])->firstWhere('key', 'lan_address')['status'] ?? null)->toBe('ok')
        ->and($tunnel->getExitCode())->toBe(2)
        ->and($tunnelJson['errors'][0]['code'])->toBe('invalid_combination');
});

it('turns the local CA off, back to the loopback URL, and deletes the CA key', function (): void {
    [$process, $json] = j06Run($this, ['config', 'set', 'phone-https', 'off', '--yes'], 'J06-local-ca-off.json');
    $state = j06State($this->dir);
    $pki = j06Docker(['run', '--rm', '--network', 'none', '--user', EdgeService::USER, '-v', EdgeService::volumeName($state['project']).':/data/caddy:ro', '--entrypoint', 'ls', $state['digests']['caddy'], '-A', '/data/caddy'], $this->journey->release->platformEnvironment());

    expect($process->getExitCode())->toBe(0, $process->getOutput())
        ->and($json['data']['url'])->toBe('http://localhost:'.$state['settings']['port'])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('local_ca_deleted')
        ->and($state['settings']['phone_https'] ?? null)->toBeNull()
        ->and(j06Container('edge'))->toBe('')
        ->and(is_file($this->dir.'/runtime/local-ca-root.crt'))->toBeFalse()
        ->and($pki->getExitCode())->toBe(0, $pki->getErrorOutput())
        ->and($pki->getOutput())->not->toContain('pki');
});

it('starts the tunnel only with --yes beside other organizations and never beside another account on the demo password', function (): void {
    [$demo] = j06Run($this, ['demo', ...j06SecretsFile($this, 'demo')], 'J06-demo.json');
    $env = $this->tunnel->environment(J06_TTL);
    [$unconfirmed, $unconfirmedJson] = j06Run($this, ['tunnel', 'start'], 'J06-tunnel-unconfirmed.json', $env);
    $app = j06Container('app');
    $setPassword = static fn (string $password): Process => j06Docker(['exec', $app, 'php', 'artisan', 'tinker', '--execute',
        "App\\Domain\\Identity\\Models\\User::query()->where('email', 'owner@example.test')->sole()->forceFill(['password' => '{$password}', 'password_known' => true])->save();"]);
    $setPassword('password');
    [$refused, $refusedJson] = j06Run($this, ['tunnel', 'start', '--yes'], 'J06-tunnel-default-password.json', $env);
    $setPassword('J6-owner-passwort-2026');

    expect($demo->getExitCode())->toBe(0, $demo->getOutput())
        ->and($unconfirmed->getExitCode())->toBe(6)
        ->and($unconfirmedJson['errors'][0]['code'])->toBe('confirmation_required')
        ->and($refused->getExitCode())->toBe(3)
        ->and($refusedJson['errors'][0]['code'])->toBe('default_password_in_use')
        ->and($refusedJson['data']['users'])->toBe(['owner@example.test'])
        ->and(j06Container('tunnel'))->toBe('');
});

it('starts the fake tunnel, rotates the demo passwords, and lets only the Field App, API and sign-in through', function (): void {
    [$process, $json] = j06Run($this, ['tunnel', 'start', '--yes', ...j06SecretsFile($this, 'tunnel-start')], 'J06-tunnel-start.json', $this->tunnel->environment(J06_TTL));
    $runtime = j06Runtime($this->dir);
    $GLOBALS['fieldsetJ06Hostname'] = $runtime['tunnel']['hostname'] ?? null;
    $paths = ['/app', '/api/v1/me', '/sanctum/csrf-cookie', '/platform', '/admin', '/horizon', '/phone-trust', '/docs/api'];
    $answers = array_combine($paths, array_map(fn (string $path): array => j06ThroughTunnel($this->dir, $path), $paths));
    $forged = j06ThroughTunnel($this->dir, '/platform', ['X-Forwarded-Host: localhost', 'X-Forwarded-For: 127.0.0.1']);
    $this->journey->evidence('J06-tunnel-paths.json', [...array_map(static fn (array $answer): int => $answer['status'], $answers), '/platform with X-Forwarded-Host: localhost' => $forged['status']]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($json['data']['url'])->toMatch('/^https:\/\/fieldset-test-[0-9a-f]+\.trycloudflare\.com$/')
        ->and($json['data']['tunnel'])->toMatchArray(['hostname' => $GLOBALS['fieldsetJ06Hostname'], 'admin' => false, 'peer' => $runtime['tunnel']['peer']])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('tunnel_third_party', 'tunnel_expires', 'url_changed_phone_data', 'demo_passwords_rotated')
        ->and(collect($json['secrets'])->pluck('name')->filter(static fn (string $name): bool => str_starts_with($name, 'demo_password:'))->count())->toBe(9)
        ->and(j06State($this->dir)['settings'])->toMatchArray(['phone_https' => 'tunnel'])
        ->and($answers['/app']['status'])->toBe(200)
        ->and($answers['/api/v1/me']['status'])->toBe(401)
        ->and($answers['/sanctum/csrf-cookie']['status'])->toBe(204)
        ->and($answers['/platform']['status'])->toBe(404)
        ->and($answers['/admin']['status'])->toBe(404)
        ->and($answers['/horizon']['status'])->toBe(404)
        ->and($answers['/phone-trust']['status'])->toBe(404)
        ->and($answers['/docs/api']['status'])->toBe(404)
        ->and($forged['status'])->toBe(404);
});

it('refuses the local CA and domain mode next to the tunnel (R61)', function (): void {
    [$localCa, $localCaJson] = j06Run($this, ['config', 'set', 'phone-https', 'local-ca', '--yes'], 'J06-local-ca-next-to-tunnel.json', $this->tunnel->environment(J06_TTL));
    [$domain, $domainJson] = j06Run($this, ['config', 'set', 'domain', 'fieldset-test-j06.test', '--yes'], 'J06-domain-next-to-tunnel.json', $this->tunnel->environment(J06_TTL));

    expect($localCa->getExitCode())->toBe(2)->and($localCaJson['errors'][0]['code'])->toBe('invalid_combination')
        ->and($domain->getExitCode())->toBe(2)->and($domainJson['errors'][0]['code'])->toBe('invalid_combination');
});

it('answers 503 on the expired tunnel host, reports it read-only, and the next mutating command removes it (AE6)', function (): void {
    $expires = strtotime((string) (j06Runtime($this->dir)['tunnel']['expires_at'] ?? 'now'));
    while (time() <= $expires + 1) {
        usleep(500_000);
    }
    $expired = j06ThroughTunnel($this->dir, '/app');
    $before = [md5_file($this->dir.'/state.json'), md5_file($this->dir.'/runtime/runtime.json'), j06Container('tunnel')];
    $since = time();
    [$status, $statusJson] = j06Run($this, ['status'], 'J06-status-expired.json', $this->tunnel->environment(J06_TTL));
    $changes = InstanceJourney::events($since, time() + 1, ['label=app.fieldset.instance='.$GLOBALS['fieldsetJ06Uuid'], 'type=container', 'event=create', 'event=destroy', 'event=stop']);
    $after = [md5_file($this->dir.'/state.json'), md5_file($this->dir.'/runtime/runtime.json'), j06Container('tunnel')];
    [$demo, $demoJson] = j06Run($this, ['demo', ...j06SecretsFile($this, 'next-mutating-command')], 'J06-next-mutating-command.json', $this->tunnel->environment(J06_TTL));
    $this->journey->evidence('J06-expiry.json', ['through_tunnel_after_expiry' => ['status' => $expired['status'], 'retry_after' => $expired['headers']['retry-after'] ?? null], 'status_changed_files' => $before !== $after, 'status_container_events' => $changes]);

    expect($expired['status'])->toBe(503)
        ->and($expired['headers']['retry-after'] ?? null)->toBe('300')
        ->and($status->getExitCode())->toBe(0, $status->getOutput())
        ->and($statusJson['data']['tunnel'])->toMatchArray(['state' => 'expired', 'hostname' => $GLOBALS['fieldsetJ06Hostname']])
        ->and(collect($statusJson['warnings'])->pluck('code')->all())->toContain('tunnel_expired')
        ->and($after)->toBe($before)
        ->and(array_filter($changes, static fn (string $event): bool => ! str_contains($event, 'smoke') && ! str_contains($event, 'run-')))->toBe([])
        ->and($demo->getExitCode())->toBe(0, $demo->getOutput())
        ->and(collect($demoJson['warnings'])->pluck('code')->all())->toContain('tunnel_removed')
        ->and(j06Container('tunnel'))->toBe('')
        ->and(j06Runtime($this->dir)['tunnel']['hostname'])->toBeNull()
        ->and(j06State($this->dir)['settings']['phone_https'] ?? null)->toBeNull();
});

it('renews a living tunnel and stops it', function (): void {
    $env = $this->tunnel->environment(3600);
    [$start] = j06Run($this, ['tunnel', 'start', '--yes', ...j06SecretsFile($this, 'tunnel-start-again')], 'J06-tunnel-start-again.json', $env);
    $first = j06Runtime($this->dir)['tunnel'];
    sleep(1);
    [$renew, $renewJson] = j06Run($this, ['tunnel', 'renew'], 'J06-tunnel-renew.json', $env);
    $renewed = j06Runtime($this->dir)['tunnel'];
    [$stop, $stopJson] = j06Run($this, ['tunnel', 'stop'], 'J06-tunnel-stop.json', $env);
    [$again, $againJson] = j06Run($this, ['tunnel', 'stop'], 'J06-tunnel-stop-again.json', $env);

    expect($start->getExitCode())->toBe(0, $start->getOutput())
        ->and($renew->getExitCode())->toBe(0, $renew->getOutput())
        ->and($renewed['hostname'])->toBe($first['hostname'])
        ->and(strtotime($renewed['expires_at']))->toBeGreaterThan(strtotime($first['expires_at']))
        ->and($renewJson['data']['previous_expires_at'])->toBe($first['expires_at'])
        ->and($stop->getExitCode())->toBe(0, $stop->getOutput())
        ->and($stopJson['data']['url'])->toStartWith('http://localhost:')
        ->and(j06Container('tunnel'))->toBe('')
        ->and(j06Runtime($this->dir)['tunnel']['hostname'])->toBeNull()
        ->and($again->getExitCode())->toBe(0)
        ->and($againJson['changed'])->toBeFalse();
});

it('uninstalls with everything it created', function (): void {
    $uuid = $GLOBALS['fieldsetJ06Uuid'];
    $process = $this->journey->fieldset($this->dir, ['uninstall', '--yes', '--json']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($uuid))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});
