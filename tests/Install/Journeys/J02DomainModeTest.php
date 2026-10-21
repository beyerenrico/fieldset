<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\AcmeHarness;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J2 (BUILD_PROMPT_V2 §11, plan U8; ADR 0075): domain mode against Pebble with challtestsrv. install.sh → fieldset install
 * --domain with Pebble as the ACME directory → the edge gets a certificate whose chain verifies against Pebble's
 * current issuing root → HTTP redirects to HTTPS, the 1.0 headers and the preview CORS arrive unchanged, only the
 * edge publishes 80, 443 and 443/udp → /_mail/ answers 401/302 without a /platform session and opens for an operator
 * with MFA (audited once) → a forged X-Forwarded-For/-Host from a container on another network is not trusted →
 * fieldset uninstall --yes removes everything, the certificate volume included, with the rate-limit warning.
 *
 * The ops container resolves the domain through FIELDSET_TEST_DNS (harness images only): it runs on the engine's default
 * network and cannot ask challtestsrv. Requests from the host go to 127.0.0.1:80/443 with the domain as SNI and Host.
 */

const J02_DOMAIN = 'fieldset-test-j02.test';
const J02_OWNER = 'owner@example.test';

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fieldsetJ02'] = new InstanceJourney($harness, $release, $harness->directory.'/j02');
        $GLOBALS['fieldsetJ02Acme'] = AcmeHarness::start($harness);
    } catch (Throwable $exception) {
        $GLOBALS['fieldsetJ02Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fieldsetJ02'])) {
        InstanceJourney::cleanup($GLOBALS['fieldsetJ02']->work.'/fieldset-test-j02', $GLOBALS['fieldsetJ02Uuid'] ?? null);
    }
    if (isset($GLOBALS['fieldsetJ02Acme'])) {
        $GLOBALS['fieldsetJ02Acme']->stop();
    }
    unset($GLOBALS['fieldsetJ02'], $GLOBALS['fieldsetJ02Acme'], $GLOBALS['fieldsetJ02Error'], $GLOBALS['fieldsetJ02Uuid'], $GLOBALS['fieldsetJ02Root']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fieldsetJ02Error'])) {
        throw $GLOBALS['fieldsetJ02Error'];
    }
    $this->journey = $GLOBALS['fieldsetJ02'];
    $this->acme = $GLOBALS['fieldsetJ02Acme'];
    $this->dir = $this->journey->work.'/fieldset-test-j02';
    $this->env = ['FIELDSET_TEST_DNS' => J02_DOMAIN.'='.j02HostAddress()];
});

/** An address the doctor accepts as this host's: a LAN address the shim reports, else a public one (NAT warning). */
function j02HostAddress(): string
{
    $process = new Process(['/bin/sh', '-c', '. "$1"; fieldset_lan_addresses', 'sh', TestImage::repository().'/distribution/shim.sh'], null, ['FIELDSET_SHIM_LIBRARY' => '1']);
    $process->run();
    $addresses = array_values(array_filter(preg_split('/\s+/', trim($process->getOutput())) ?: []));

    return $addresses[0] ?? '1.1.1.1';
}

/** HTTPS and HTTP to the edge through the host's 80/443, with the domain as SNI and Host and Pebble's root trusted. */
function j02Client(): Client
{
    return new Client([
        'http_errors' => false, 'allow_redirects' => false, 'timeout' => 30,
        RequestOptions::VERIFY => $GLOBALS['fieldsetJ02Root'] ?? false,
        'curl' => [CURLOPT_RESOLVE => [J02_DOMAIN.':443:127.0.0.1', J02_DOMAIN.':80:127.0.0.1']],
    ]);
}

/** @param  list<string>  $command */
function j02Docker(array $command, array $env = []): Process
{
    $process = new Process(['docker', ...$command], null, $env);
    $process->setTimeout(180);
    $process->run();

    return $process;
}

it('installs in domain mode and gets a certificate from Pebble', function (): void {
    $secrets = $this->journey->work.'/owner-link.json';
    $flags = [
        '--org', 'J2 Org', '--owner-email', J02_OWNER, '--locale', 'en', '--domain', J02_DOMAIN, '--acme-email', 'ops@example.test',
        '--acme-ca', AcmeHarness::DIRECTORY_URL, '--acme-ca-root-file', $this->acme->httpsRoot, '--secrets-file', $secrets, '--json',
    ];
    $started = microtime(true);
    $process = $this->journey->installProcess($this->dir, $flags, $this->env);
    $process->start();
    $connected = null;
    while ($process->isRunning()) {
        $uuid = InstanceJourney::uuid($this->dir);
        $edge = $uuid === null || $connected !== null ? '' : trim(j02Docker(['ps', '-aq', '--filter', 'label=app.fieldset.role=edge', '--filter', "label=app.fieldset.instance={$uuid}"])->getOutput());
        if ($edge !== '') {
            $this->acme->connectEdge($edge);
            $connected = j02Docker(['inspect', '-f', '{{.State.Status}}', $edge])->getOutput();
        }
        usleep(200_000);
    }
    $json = InstanceJourney::json($process);
    $GLOBALS['fieldsetJ02Uuid'] = InstanceJourney::uuid($this->dir);
    $state = json_decode((string) @file_get_contents($this->dir.'/state.json'), true);
    $runtime = json_decode((string) @file_get_contents($this->dir.'/runtime/runtime.json'), true);
    $this->journey->evidence('J02-install.json', ['command' => 'install.sh --dir <work>/fieldset-test-j02 --version '.ReleaseHarness::version().' '.implode(' ', array_slice($flags, 0, 12)).' --acme-ca-root-file <harness>/pebble-https-root.pem --secrets-file <work>/owner-link.json --json', 'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $started, 1), 'edge_connected_while' => trim((string) $connected), 'stdout' => $json], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput()."\n".$this->acme->logs())
        ->and($json['data'])->toMatchArray(['url' => 'https://'.J02_DOMAIN, 'mode' => 'domain', 'port' => null])
        ->and($json['data']['mail']['catcher_url'])->toBe('https://'.J02_DOMAIN.'/_mail/')
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and($state['mode'])->toBe('domain')
        ->and($state['settings']['acme_ca'])->toBe(AcmeHarness::DIRECTORY_URL)
        ->and($runtime['instance_url'])->toBe('https://'.J02_DOMAIN)
        ->and($connected)->not->toBeNull();
});

it('serves a certificate whose chain verifies against Pebble\'s current issuing root', function (): void {
    $root = $this->journey->work.'/pebble-issuing-root.pem';
    file_put_contents($root, $this->acme->issuingRoot());
    $GLOBALS['fieldsetJ02Root'] = $root;
    $context = stream_context_create(['ssl' => ['cafile' => $root, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => J02_DOMAIN, 'capture_peer_cert' => true]]);
    $socket = @stream_socket_client('ssl://127.0.0.1:443', $errno, $error, 30, STREAM_CLIENT_CONNECT, $context);
    $certificate = $socket === false ? false : openssl_x509_parse(stream_context_get_params($socket)['options']['ssl']['peer_certificate']);
    $untrusted = @stream_socket_client('ssl://127.0.0.1:443', $errno, $refused, 30, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['cafile' => $this->acme->httpsRoot, 'peer_name' => J02_DOMAIN]]));
    $this->journey->evidence('J02-certificate.json', ['subject' => $certificate['subject'] ?? null, 'issuer' => $certificate['issuer'] ?? null, 'san' => $certificate['extensions']['subjectAltName'] ?? null], 'M10');

    expect($socket)->not->toBeFalse("TLS to the edge failed: {$error}")
        ->and($certificate['extensions']['subjectAltName'] ?? '')->toBe('DNS:'.J02_DOMAIN)
        ->and($certificate['issuer']['CN'] ?? '')->toContain('Pebble Intermediate')
        ->and($untrusted)->toBeFalse();
});

it('redirects HTTP to HTTPS and passes the app\'s security headers and preview CORS through unchanged', function (): void {
    $http = j02Client()->get('http://'.J02_DOMAIN.'/admin/login');
    $login = j02Client()->get('https://'.J02_DOMAIN.'/admin/login');
    $shell = j02Client()->get('https://'.J02_DOMAIN.'/form-preview');
    preg_match('#/form-preview/assets/[A-Za-z0-9._-]+\.js#', (string) $shell->getBody(), $asset);
    $previewAsset = j02Client()->get('https://'.J02_DOMAIN.($asset[0] ?? '/form-preview/assets/missing.js'));
    $app = j02Client()->get('https://'.J02_DOMAIN.'/app');
    $headers = static fn ($response): array => array_map(static fn (array $values): string => implode(', ', $values), $response->getHeaders());
    $this->journey->evidence('J02-headers.json', ['http' => [$http->getStatusCode(), $http->getHeaderLine('Location')], 'admin_login' => $headers($login), 'preview_asset' => $headers($previewAsset), 'field_app' => $headers($app)], 'M10');

    expect($http->getStatusCode())->toBe(308)
        ->and($http->getHeaderLine('Location'))->toBe('https://'.J02_DOMAIN.'/admin/login')
        ->and($login->getStatusCode())->toBe(200)
        ->and($login->getHeaderLine('Strict-Transport-Security'))->toContain('max-age=')
        ->and($login->getHeaderLine('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($login->getHeaderLine('X-Frame-Options'))->toBe('DENY')
        // The app sets it in its own Caddyfile and in SecurityHeaders (1.0 behaviour): two values, passed through as sent.
        ->and(array_unique(explode(', ', $login->getHeaderLine('X-Content-Type-Options'))))->toBe(['nosniff'])
        ->and($login->getHeaderLine('Referrer-Policy'))->not->toBe('')
        ->and($login->getHeaderLine('Permissions-Policy'))->not->toBe('')
        ->and($login->getHeader('Set-Cookie'))->each->toContain('secure')
        ->and(implode("\n", $login->getHeader('Set-Cookie')))->toContain('__Host-')
        ->and($asset)->not->toBe([])
        ->and($previewAsset->getStatusCode())->toBe(200)
        ->and($previewAsset->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*')
        ->and($app->getStatusCode())->toBe(200)
        ->and($app->getHeaderLine('Content-Security-Policy'))->not->toBe('')
        ->and($app->getHeaderLine('Content-Security-Policy'))->not->toContain("frame-ancestors 'self'");
});

it('publishes only 80, 443 and 443/udp, all from the edge', function (): void {
    $published = [];
    foreach (InstanceJourney::labelled($GLOBALS['fieldsetJ02Uuid'])['containers'] as $name) {
        $ports = array_values(array_filter(explode("\n", trim(j02Docker(['port', $name])->getOutput()))));
        if ($ports !== []) {
            $published[$name] = $ports;
        }
    }
    $this->journey->evidence('J02-ports.json', ['published' => $published], 'M10');

    expect(array_keys($published))->toHaveCount(1)
        ->and(array_key_first($published))->toEndWith('-edge-1')
        ->and(array_values(array_unique(array_map(static fn (string $line): string => explode(' -> ', $line)[0], $published[array_key_first($published)]))))
        ->toEqualCanonicalizing(['80/tcp', '443/tcp', '443/udp']);
});

it('keeps /_mail/ behind a /platform session with MFA and audits the opening once', function (): void {
    $client = j02Client();
    $navigate = $client->get('https://'.J02_DOMAIN.'/_mail/', ['headers' => ['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate']]);
    $xhr = $client->get('https://'.J02_DOMAIN.'/_mail/api/v1/messages', ['headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']]);
    $internal = $client->get('https://'.J02_DOMAIN.'/_fieldset/catcher-auth');
    $app = trim(j02Docker(['ps', '-q', '--filter', 'label=app.fieldset.instance='.$GLOBALS['fieldsetJ02Uuid'], '--filter', 'label=app.fieldset.role=app'])->getOutput());
    j02Docker(['cp', TestImage::repository().'/tests/Install/Harness/operator-session.php', "{$app}:/tmp/operator-session.php"]);
    $session = json_decode(j02Docker(['exec', $app, 'php', '/tmp/operator-session.php', J02_OWNER])->getOutput(), true);
    $cookie = ['Cookie' => ($session['name'] ?? '').'='.($session['value'] ?? '')];
    $ui = $client->get('https://'.J02_DOMAIN.'/_mail/', ['headers' => [...$cookie, 'Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate']]);
    $api = $client->get('https://'.J02_DOMAIN.'/_mail/api/v1/messages', ['headers' => [...$cookie, 'Accept' => 'application/json']]);
    $postgres = trim(j02Docker(['ps', '-q', '--filter', 'label=app.fieldset.instance='.$GLOBALS['fieldsetJ02Uuid'], '--filter', 'label=app.fieldset.role=postgres'])->getOutput());
    $audits = trim(j02Docker(['exec', $postgres, 'psql', '-U', 'fieldset', '-d', 'fieldset', '-tAc', "SELECT count(*) FROM activity_log WHERE event = 'platform.catcher_opened'"])->getOutput());
    $this->journey->evidence('J02-catcher.json', [
        'without_session' => ['navigate' => [$navigate->getStatusCode(), $navigate->getHeaderLine('Location')], 'xhr' => $xhr->getStatusCode(), 'auth_endpoint_from_outside' => $internal->getStatusCode()],
        'operator_with_mfa' => ['ui' => $ui->getStatusCode(), 'api' => $api->getStatusCode(), 'csp' => $ui->getHeader('Content-Security-Policy'), 'x_frame_options' => $ui->getHeaderLine('X-Frame-Options')],
        'catcher_opened_audits' => $audits,
    ], 'M10');

    expect($navigate->getStatusCode())->toBe(302)
        ->and($navigate->getHeaderLine('Location'))->toContain('/platform/login')
        ->and($navigate->getHeaderLine('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($xhr->getStatusCode())->toBe(401)
        ->and($xhr->getHeaderLine('Cache-Control'))->toContain('no-store')
        ->and($internal->getStatusCode())->toBe(404)
        ->and($session['name'] ?? '')->toStartWith('__Host-')
        ->and($ui->getStatusCode())->toBe(200)
        ->and((string) $ui->getBody())->toContain('Mailpit')
        ->and($ui->getHeaderLine('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($ui->getHeaderLine('X-Content-Type-Options'))->toBe('nosniff')
        ->and($ui->getHeaderLine('Cache-Control'))->toBe('no-store')
        ->and($ui->getHeader('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and(implode(' ', $ui->getHeader('Content-Security-Policy')))->toContain('script-src')
        ->and($api->getStatusCode())->toBe(200)
        ->and(json_decode((string) $api->getBody(), true))->toHaveKey('messages')
        ->and($audits)->toBe('1');
});

it('trusts forwarded headers only from the edge, not from a container on another network', function (): void {
    $uuid = $GLOBALS['fieldsetJ02Uuid'];
    $state = json_decode((string) file_get_contents($this->dir.'/state.json'), true);
    $forged = ['--header', 'Host: '.J02_DOMAIN, '--header', 'X-Forwarded-Host: evil.example', '--header', 'X-Forwarded-For: 203.0.113.7'];
    $neighbour = j02Docker(['run', '--rm', '--network', $state['project'].'_default', '--label', "app.fieldset.instance={$uuid}", '--entrypoint', 'curl', $state['digests']['app'],
        '--silent', '--output', '/dev/null', '--write-out', '%{http_code}', ...$forged, 'http://app:8080/admin/login'], $this->journey->release->platformEnvironment());
    $edge = trim(j02Docker(['ps', '-q', '--filter', "label=app.fieldset.instance={$uuid}", '--filter', 'label=app.fieldset.role=edge'])->getOutput());
    $fromEdge = j02Docker(['exec', $edge, 'wget', '-q', '-S', '-O', '/dev/null', '--header', 'Host: '.J02_DOMAIN, '--header', 'X-Forwarded-Host: evil.example', 'http://app:8080/admin/login']);
    // A client's X-Forwarded-Port or Host port must not reach the links the app renders (ADR 0079): the edge strips the
    // header, and the app takes the port from the instance URL.
    $forgedPort = j02Client()->get('https://'.J02_DOMAIN.'/admin/login', ['headers' => ['X-Forwarded-Port' => '8443']]);
    $forgedHost = j02Client()->get('https://'.J02_DOMAIN.'/admin/login', ['headers' => ['Host' => J02_DOMAIN.':8443']]);
    $this->journey->evidence('J02-forwarded.json', [
        'neighbour_on_default_network' => trim($neighbour->getOutput()), 'edge_positive_control' => trim($fromEdge->getErrorOutput()),
        'forged_x_forwarded_port' => ['status' => $forgedPort->getStatusCode(), 'links_with_port' => str_contains((string) $forgedPort->getBody(), J02_DOMAIN.':8443')],
        'forged_host_port' => ['status' => $forgedHost->getStatusCode(), 'links_with_port' => str_contains((string) $forgedHost->getBody(), J02_DOMAIN.':8443')],
    ], 'M10');

    expect(trim($neighbour->getOutput()))->toBe('200', $neighbour->getErrorOutput())
        ->and($fromEdge->getErrorOutput())->toContain('400')
        ->and($forgedPort->getStatusCode())->toBe(200)
        ->and((string) $forgedPort->getBody())->toContain('https://'.J02_DOMAIN.'/')
        ->and((string) $forgedPort->getBody())->not->toContain(J02_DOMAIN.':8443')
        ->and((string) $forgedHost->getBody())->not->toContain(J02_DOMAIN.':8443');
});

it('reports the instance with fieldset status and doctor --post', function (): void {
    $status = $this->journey->fieldset($this->dir, ['status', '--json'], $this->env);
    $doctor = $this->journey->fieldset($this->dir, ['doctor', '--post', '--json'], $this->env);
    $statusJson = InstanceJourney::json($status);
    $this->journey->evidence('J02-status.json', ['command' => 'fieldset status --json', 'exit_code' => $status->getExitCode(), 'stdout' => $statusJson], 'M10');

    expect($status->getExitCode())->toBe(0, $status->getOutput())
        ->and($statusJson['data'])->toMatchArray(['mode' => 'domain', 'url' => 'https://'.J02_DOMAIN, 'health' => 'ok'])
        ->and(array_keys($statusJson['data']['services']))->toContain('edge')
        ->and($doctor->getExitCode())->toBe(0, $doctor->getOutput());
});

it('uninstalls with the certificate volume and warns about the CA\'s duplicate-certificate limit', function (): void {
    $uuid = $GLOBALS['fieldsetJ02Uuid'];
    $before = InstanceJourney::labelled($uuid);
    $process = $this->journey->fieldset($this->dir, ['uninstall', '--yes', '--json'], $this->env);
    $json = InstanceJourney::json($process);
    $this->journey->evidence('J02-uninstall.json', ['command' => 'fieldset uninstall --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'labelled_before' => $before, 'labelled_after' => InstanceJourney::labelled($uuid)], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($before['containers'])->toHaveCount(9) // with the backup service since U16 (ADR 0084)
        ->and(array_filter($before['volumes'], static fn (string $volume): bool => str_ends_with($volume, '_edge-data')))->toHaveCount(1)
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('certificates_removed')
        ->and(InstanceJourney::labelled($uuid))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});
