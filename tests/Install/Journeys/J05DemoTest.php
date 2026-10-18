<?php

declare(strict_types=1);

use App\Domain\Instance\Operations\OperationJournal;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\AcmeHarness;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J5, demo part (BUILD_PROMPT_V2 §11, AE2, R29, R30; plan U13; ADR 0081), with real Docker against the harness release:
 *
 * - A loopback-only local install with --demo whose own organization is named "Demo" (the demo is seeded beside it, M11
 *   gate): the demo users sign in with `password` (Fortify's headless login, as the Field App does, then the session
 *   reaches /api/v1/me); the app-side check finds all nine and one organization besides the demo; `fsa demo` changes
 *   no demo data and its new demo owner link signs in; `fsa config set domain` is refused in its preflight (exit 3
 *   `default_password_in_use`) with nothing changed; `fsa demo --rotate-passwords` makes `password` and an existing
 *   remembered session fail and the printed passwords work; the check then finds none.
 * - A domain-mode install with --demo against Pebble (as J2): `password` is refused, and every demo user signs in over
 *   HTTPS with the random password printed once into --secrets-file.
 *
 * Everything is named `fsa-test-j05-…` and removed afterwards. Evidence goes to the milestone of FSA_EVIDENCE_MILESTONE.
 */

const J05_DOMAIN = 'fsa-test-j05.test';
const J05_DEMO_USERS = [
    'owner@demo.test', 'admin@demo.test', 'designer@demo.test', 'reviewer@demo.test', 'coordinator@demo.test',
    'worker@demo.test', 'mobile@demo.test', 'tablet@demo.test', 'viewer@demo.test',
];

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fsaJ05'] = new InstanceJourney($harness, $release, $harness->directory.'/j05');
        $GLOBALS['fsaJ05Acme'] = AcmeHarness::start($harness);
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ05Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ05'])) {
        InstanceJourney::cleanup($GLOBALS['fsaJ05']->work.'/fsa-test-j05-local', $GLOBALS['fsaJ05LocalUuid'] ?? null);
        InstanceJourney::cleanup($GLOBALS['fsaJ05']->work.'/fsa-test-j05-domain', $GLOBALS['fsaJ05DomainUuid'] ?? null);
    }
    if (isset($GLOBALS['fsaJ05Acme'])) {
        $GLOBALS['fsaJ05Acme']->stop();
    }
    unset($GLOBALS['fsaJ05'], $GLOBALS['fsaJ05Acme'], $GLOBALS['fsaJ05Error'], $GLOBALS['fsaJ05LocalUuid'], $GLOBALS['fsaJ05DomainUuid'], $GLOBALS['fsaJ05Port'], $GLOBALS['fsaJ05Root'], $GLOBALS['fsaJ05Session']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ05Error'])) {
        throw $GLOBALS['fsaJ05Error'];
    }
    $this->journey = $GLOBALS['fsaJ05'];
    $this->acme = $GLOBALS['fsaJ05Acme'];
    $this->local = $this->journey->work.'/fsa-test-j05-local';
    $this->domain = $this->journey->work.'/fsa-test-j05-domain';
});

/** An address the doctor accepts as this host's: a LAN address the shim reports, else a public one (as in J2). */
function j05HostAddress(): string
{
    $process = new Process(['/bin/sh', '-c', '. "$1"; fsa_lan_addresses', 'sh', TestImage::repository().'/distribution/shim.sh'], null, ['FSA_SHIM_LIBRARY' => '1']);
    $process->run();

    return array_values(array_filter(preg_split('/\s+/', trim($process->getOutput())) ?: []))[0] ?? '1.1.1.1';
}

/** A client with its own cookie jar: on the local port, or on the edge's 443 with the domain as SNI and Host. */
function j05Client(?int $port = null): Client
{
    $options = ['http_errors' => false, 'allow_redirects' => false, 'timeout' => 30, 'cookies' => new CookieJar];

    return new Client($port !== null ? [...$options, 'base_uri' => "http://localhost:{$port}"] : [
        ...$options, 'base_uri' => 'https://'.J05_DOMAIN, RequestOptions::VERIFY => $GLOBALS['fsaJ05Root'] ?? false,
        'curl' => [CURLOPT_RESOLVE => [J05_DOMAIN.':443:127.0.0.1', J05_DOMAIN.':80:127.0.0.1']],
    ]);
}

/** Fortify's headless login as the Field App does it: CSRF cookie, then POST /auth/login with the XSRF header. */
function j05Login(Client $client, string $email, string $password, bool $remember = false): ResponseInterface
{
    $origin = rtrim((string) $client->getConfig('base_uri'), '/');
    $spa = ['Referer' => $origin.'/app', 'Origin' => $origin, 'Accept' => 'application/json'];
    $client->get('/sanctum/csrf-cookie', ['headers' => $spa]);
    $xsrf = $client->getConfig('cookies')->getCookieByName('XSRF-TOKEN')?->getValue() ?? '';

    return $client->post('/auth/login', ['headers' => [...$spa, 'X-XSRF-TOKEN' => urldecode($xsrf)], 'json' => ['email' => $email, 'password' => $password, 'remember' => $remember]]);
}

/** GET /api/v1/me with the client's session: the e-mail of the signed-in user, or null with the status. */
function j05Me(Client $client): array
{
    $origin = rtrim((string) $client->getConfig('base_uri'), '/');
    $response = $client->get('/api/v1/me', ['headers' => ['Referer' => $origin.'/app', 'Origin' => $origin, 'Accept' => 'application/json']]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)['data']['user']['email'] ?? null];
}

/** @return array<string, string> secret name => value, from a --secrets-file */
function j05Secrets(string $file): array
{
    $document = json_decode((string) @file_get_contents($file), true);

    return array_column(is_array($document) ? ($document['secrets'] ?? []) : [], 'value', 'name');
}

/** `php artisan app:demo-passwords --check --json` inside the instance's app container (U14's check, for real). */
function j05Check(string $uuid): array
{
    $app = trim(j05Docker(['ps', '-q', '--filter', "label=app.form-schema.instance={$uuid}", '--filter', 'label=app.form-schema.role=app'])->getOutput());
    $process = j05Docker(['exec', $app, 'php', 'artisan', 'app:demo-passwords', '--check', '--json']);

    return json_decode(trim($process->getOutput()), true) ?? ['raw' => $process->getOutput().$process->getErrorOutput()];
}

/** @param  list<string>  $command */
function j05Docker(array $command): Process
{
    $process = new Process(['docker', ...$command]);
    $process->setTimeout(300);
    $process->run();

    return $process;
}

it('installs a loopback-only local demo whose users sign in with password', function (): void {
    $link = $this->journey->work.'/j05-owner-link.json';
    $started = microtime(true);
    $process = $this->journey->install($this->local, ['--org', 'Demo', '--owner-email', 'owner@example.test', '--locale', 'en', '--demo', '--secrets-file', $link, '--json']);
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaJ05LocalUuid'] = InstanceJourney::uuid($this->local);
    $GLOBALS['fsaJ05Port'] = $json['data']['port'] ?? null;
    $client = j05Client($GLOBALS['fsaJ05Port']);
    $login = j05Login($client, 'worker@demo.test', 'password');
    $me = j05Me($client);
    $this->journey->evidence('J05-local-install.json', ['command' => 'install.sh --dir <work>/fsa-test-j05-local --version '.ReleaseHarness::version().' --org Demo --owner-email owner@example.test --locale en --demo --secrets-file <work>/j05-owner-link.json --json', 'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json, 'login_with_password' => [$login->getStatusCode(), json_decode((string) $login->getBody(), true)], 'me' => $me]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['demo'])->toMatchArray(['organization' => 'Demo Haustechnik GmbH', 'seeded' => true, 'passwords' => 'default', 'default_password' => 'password', 'exposure' => [], 'delivered' => 'data'])
        ->and(array_column($json['data']['demo']['users'], 'email'))->toBe(J05_DEMO_USERS)
        ->and(array_keys(j05Secrets($link)))->toBe(['sign_in_link'])
        ->and($login->getStatusCode())->toBe(200, (string) $login->getBody())
        ->and($me)->toBe([200, 'worker@demo.test']);
});

it('finds all nine demo accounts on password and the owner\'s organization "Demo" as a real one with the app-side check', function (): void {
    $check = j05Check($GLOBALS['fsaJ05LocalUuid']);
    $this->journey->evidence('J05-local-check.json', ['command' => 'docker exec <app> php artisan app:demo-passwords --check --json', 'stdout' => $check]);

    expect($check['status'] ?? null)->toBe('ok', json_encode($check))
        ->and($check['data']['count'])->toBe(9)
        ->and(array_column($check['data']['users'], 'email'))->toEqualCanonicalizing(J05_DEMO_USERS)
        ->and(array_unique(array_column($check['data']['users'], 'demo')))->toBe([true])
        ->and($check['data']['organizations'])->toBe(['total' => 2, 'non_demo' => 1]);
});

it('changes no demo data with fsa demo on the seeded instance and reports the new demo owner link, which signs in', function (): void {
    $file = $this->journey->work.'/j05-demo-link.json';
    $process = $this->journey->fsa($this->local, ['demo', '--secrets-file', $file, '--json']);
    $json = InstanceJourney::json($process);
    [, $token] = explode('#', j05Secrets($file)['sign_in_link'] ?? '#', 2);
    $client = j05Client($GLOBALS['fsaJ05Port']);
    preg_match('/name="_token" value="([^"]+)"/', (string) $client->get('/login-link')->getBody(), $csrf);
    $consumed = $client->post('/login-link', ['form_params' => ['_token' => $csrf[1] ?? '', 'token' => $token]]);
    $this->journey->evidence('J05-local-demo.json', ['command' => 'fsa demo --secrets-file <work>/j05-demo-link.json --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'link_consumed' => [$consumed->getStatusCode(), $consumed->getHeaderLine('Location')]]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($json['changed'])->toBeTrue()
        ->and(array_column($json['warnings'], 'code'))->toBe(['sign_in_link_issued'])
        ->and($json['data']['demo'])->toMatchArray(['seeded' => false, 'passwords' => 'default', 'default_password_users' => 9])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and($process->getOutput())->not->toContain('/login-link#')
        ->and($token)->not->toBe('')
        ->and($consumed->getStatusCode())->toBe(303)
        ->and($consumed->getHeaderLine('Location'))->toContain('/admin')
        ->and($consumed->getHeaderLine('Location'))->not->toContain($token);
});

it('refuses config set domain while the demo accounts sign in with password: exit 3 in the preflight, nothing changed', function (): void {
    $state = (string) file_get_contents($this->local.'/state.json');
    $started = microtime(true);
    $process = $this->journey->fsa($this->local, ['config', 'set', 'domain', J05_DOMAIN, '--yes', '--json']);
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $client = j05Client($GLOBALS['fsaJ05Port']);
    $login = j05Login($client, 'viewer@demo.test', 'password');
    $this->journey->evidence('J05-local-config-domain-refused.json', ['command' => 'fsa config set domain '.J05_DOMAIN.' --yes --json', 'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json, 'state_unchanged' => $state === file_get_contents($this->local.'/state.json'), 'login_with_password_on_loopback' => $login->getStatusCode()]);

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput().$process->getOutput())
        ->and($json['errors'][0]['code'])->toBe('default_password_in_use')
        ->and($json['errors'][0]['hint'])->toContain('fsa demo --rotate-passwords')
        ->and($json['data']['users'])->toEqualCanonicalizing(J05_DEMO_USERS)
        ->and(file_get_contents($this->local.'/state.json'))->toBe($state)
        ->and(file_exists($this->local.'/'.OperationJournal::CURRENT))->toBeFalse()
        ->and($login->getStatusCode())->toBe(200, (string) $login->getBody())
        ->and(j05Me($client))->toBe([200, 'viewer@demo.test']);
});

it('rotates the demo passwords: password and a remembered session stop working, the printed passwords work', function (): void {
    $session = j05Client($GLOBALS['fsaJ05Port']);
    $before = j05Login($session, 'mobile@demo.test', 'password', remember: true);
    $meBefore = j05Me($session);
    $file = $this->journey->work.'/j05-rotated.json';
    $process = $this->journey->fsa($this->local, ['demo', '--rotate-passwords', '--secrets-file', $file, '--json']);
    $json = InstanceJourney::json($process);
    $passwords = j05Secrets($file);
    $meAfter = j05Me($session);
    $old = j05Login(j05Client($GLOBALS['fsaJ05Port']), 'mobile@demo.test', 'password');
    $fresh = j05Client($GLOBALS['fsaJ05Port']);
    $new = j05Login($fresh, 'mobile@demo.test', $passwords['demo_password:mobile@demo.test'] ?? '');
    $check = j05Check($GLOBALS['fsaJ05LocalUuid']);
    $this->journey->evidence('J05-local-rotate.json', [
        'command' => 'fsa demo --rotate-passwords --secrets-file <work>/j05-rotated.json --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json,
        'session_before' => [$before->getStatusCode(), $meBefore], 'session_after' => $meAfter, 'old_password' => $old->getStatusCode(), 'new_password' => [$new->getStatusCode(), j05Me($fresh)],
        'check_after' => $check['data'] ?? $check,
    ]);

    expect($before->getStatusCode())->toBe(200, (string) $before->getBody())
        ->and($meBefore)->toBe([200, 'mobile@demo.test'])
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['demo'])->toMatchArray(['rotated' => true, 'passwords' => 'random', 'delivered' => 'secrets_file'])
        ->and($json['data']['demo']['sessions_ended'])->toBeGreaterThanOrEqual(1)
        ->and(array_keys($passwords))->toBe(array_map(static fn (string $email): string => 'demo_password:'.$email, J05_DEMO_USERS))
        ->and($process->getOutput())->not->toContain($passwords['demo_password:mobile@demo.test'] ?? 'missing')
        ->and($meAfter[0])->toBe(401)
        ->and($old->getStatusCode())->toBe(422)
        ->and($new->getStatusCode())->toBe(200, (string) $new->getBody())
        ->and($check['data']['count'] ?? null)->toBe(0);
});

it('uninstalls the local demo without leaving anything labelled behind', function (): void {
    $process = $this->journey->fsa($this->local, ['uninstall', '--yes', '--json']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($GLOBALS['fsaJ05LocalUuid']))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});

it('installs a domain-mode demo: password is refused and every demo user signs in with its printed random password', function (): void {
    $secrets = $this->journey->work.'/j05-domain-secrets.json';
    $flags = ['--org', 'J5 Domain Org', '--owner-email', 'owner@example.test', '--locale', 'en', '--domain', J05_DOMAIN, '--acme-ca', AcmeHarness::DIRECTORY_URL, '--acme-ca-root-file', $this->acme->httpsRoot, '--demo', '--secrets-file', $secrets, '--json'];
    $started = microtime(true);
    $process = $this->journey->installProcess($this->domain, $flags, ['FSA_TEST_DNS' => J05_DOMAIN.'='.j05HostAddress()]);
    $process->start();
    $connected = false;
    while ($process->isRunning()) {
        $uuid = InstanceJourney::uuid($this->domain);
        $edge = $uuid === null || $connected ? '' : trim(j05Docker(['ps', '-aq', '--filter', 'label=app.form-schema.role=edge', '--filter', "label=app.form-schema.instance={$uuid}"])->getOutput());
        if ($edge !== '') {
            $this->acme->connectEdge($edge);
            $connected = true;
        }
        usleep(200_000);
    }
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaJ05DomainUuid'] = InstanceJourney::uuid($this->domain);
    $root = $this->journey->work.'/j05-pebble-root.pem';
    file_put_contents($root, $process->isSuccessful() ? $this->acme->issuingRoot() : '');
    $GLOBALS['fsaJ05Root'] = $root;
    $passwords = j05Secrets($secrets);
    $refused = j05Login(j05Client(), 'owner@demo.test', 'password');
    $logins = [];
    foreach (J05_DEMO_USERS as $email) {
        $client = j05Client();
        $logins[$email] = [j05Login($client, $email, $passwords['demo_password:'.$email] ?? '')->getStatusCode(), j05Me($client)[1]];
    }
    $this->journey->evidence('J05-domain-install.json', [
        'command' => 'install.sh --dir <work>/fsa-test-j05-domain --version '.ReleaseHarness::version().' --org "J5 Domain Org" --owner-email owner@example.test --locale en --domain '.J05_DOMAIN.' --acme-ca '.AcmeHarness::DIRECTORY_URL.' --acme-ca-root-file <harness>/pebble-https-root.pem --demo --secrets-file <work>/j05-domain-secrets.json --json',
        'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json, 'secret_names' => array_keys($passwords),
        'login_with_password' => $refused->getStatusCode(), 'logins_with_printed_passwords' => $logins,
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput()."\n".$this->acme->logs())
        ->and($json['data'])->toMatchArray(['url' => 'https://'.J05_DOMAIN, 'mode' => 'domain'])
        ->and($json['data']['demo'])->toMatchArray(['seeded' => true, 'passwords' => 'random', 'exposure' => ['domain_mode'], 'delivered' => 'secrets_file'])
        ->and($json['data']['demo'])->not->toHaveKey('default_password')
        ->and(array_keys($passwords))->toBe(['sign_in_link', ...array_map(static fn (string $email): string => 'demo_password:'.$email, J05_DEMO_USERS)])
        ->and(array_map(strlen(...), array_values(array_slice($passwords, 1))))->each->toBe(24)
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain($passwords['demo_password:owner@demo.test'] ?? 'missing')
        ->and($refused->getStatusCode())->toBe(422)
        ->and($logins)->toBe(array_combine(J05_DEMO_USERS, array_map(static fn (string $email): array => [200, $email], J05_DEMO_USERS)));
});

it('uninstalls the domain demo without leaving anything labelled behind', function (): void {
    $process = $this->journey->fsa($this->domain, ['uninstall', '--yes', '--json'], ['FSA_TEST_DNS' => J05_DOMAIN.'='.j05HostAddress()]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($GLOBALS['fsaJ05DomainUuid']))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});
