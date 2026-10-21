<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J3 (BUILD_PROMPT_V2 §11, plan U9, ADR 0076): proxy mode behind the harness Traefik on a shared external
 * network, with the harness release and real Docker. install.sh --behind-proxy traefik → only the app joins the
 * network and the app trusts exactly Traefik's address → a client reaches the configured host through Traefik
 * over TLS and the app honours the forwarded scheme and client address → a neighbour container on the same
 * network that talks to the app directly with forged X-Forwarded-* is not trusted (the app's own session row
 * records what it saw) → the release's stack file runs with plain `docker compose` and only environment
 * variables → fsa uninstall leaves the proxy and its network alone. Everything is named fsa-test-…; transcripts go
 * to docs/agent/milestones/evidence/M10/.
 */

const J03_HOST = 'forms.fsa-test.example';

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fsaJ03'] = new InstanceJourney($harness, $release, $harness->directory.'/j03');
        $GLOBALS['fsaJ03Proxy'] = $harness->startProxy();
        $GLOBALS['fsaJ03Id'] = bin2hex(random_bytes(3));
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ03Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ03'])) {
        foreach (['client', 'neighbour'] as $role) {
            new Process(['docker', 'rm', '-f', j03Name($role)])->setTimeout(120)->run();
        }
        InstanceJourney::cleanup($GLOBALS['fsaJ03']->work.'/fsa-test-j03', $GLOBALS['fsaJ03Uuid'] ?? null);
        $GLOBALS['fsaJ03']->harness->stopProxy();
    }
    unset($GLOBALS['fsaJ03'], $GLOBALS['fsaJ03Error'], $GLOBALS['fsaJ03Uuid'], $GLOBALS['fsaJ03Proxy'], $GLOBALS['fsaJ03Id']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ03Error'])) {
        throw $GLOBALS['fsaJ03Error'];
    }
    $this->journey = $GLOBALS['fsaJ03'];
    $this->proxy = $GLOBALS['fsaJ03Proxy'];
    $this->dir = $this->journey->work.'/fsa-test-j03';
});

function j03Name(string $role): string
{
    return 'fsa-test-j03-'.$role.'-'.($GLOBALS['fsaJ03Id'] ?? 'x');
}

/** @param  list<string>  $command */
function j03Run(array $command, array $env = [], int $timeout = 300): Process
{
    $process = new Process($command, null, $env === [] ? null : $env);
    $process->setTimeout($timeout);
    $process->run();

    return $process;
}

function j03Address(string $container, string $network): string
{
    return trim(j03Run(['docker', 'inspect', '-f', '{{with index .NetworkSettings.Networks "'.$network.'"}}{{.IPAddress}}{{end}}', $container])->getOutput());
}

/** A long-running container of the app image (it has curl) on the shared network. */
function j03Container(InstanceJourney $journey, string $role, string $network): string
{
    $name = j03Name($role);
    j03Run(['docker', 'rm', '-f', $name]);
    $process = j03Run(['docker', 'run', '-d', '--name', $name, '--network', $network, '--entrypoint', 'sleep', $journey->release->appReference, '1800'], [...getenv(), ...$journey->release->platformEnvironment()]);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return $name;
}

/** @return list<string> the IP addresses the app recorded for sessions opened with this User-Agent */
function j03SessionAddresses(string $project, string $agent): array
{
    $process = j03Run(['docker', 'exec', $project.'-postgres-1', 'psql', '-U', 'form_schema', '-d', 'form_schema', '-At', '-c', "select distinct ip_address from sessions where user_agent = '{$agent}'"]);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return array_values(array_filter(explode("\n", trim($process->getOutput()))));
}

/** @return list<string> response header lines with cookie values and the CSP nonce replaced */
function j03RedactedHeaders(string $headers): array
{
    return array_values(array_filter(array_map(
        static fn (string $line): string => (string) preg_replace(['/^(set-cookie: [^=]+=)[^;]*/i', "/'nonce-[^']+'/"], ['$1<redacted>', "'nonce-<redacted>'"], $line),
        explode("\r\n", $headers),
    )));
}

/** A loopback port nothing listens on right now (the OS picks it). */
function j03FreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = $server === false ? '' : (string) stream_socket_get_name($server, false);
    if ($server !== false) {
        fclose($server);
    }

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/** @return array{status: int, headers: string, body: string} */
function j03Curl(string $container, array $arguments, int $attempts = 1): array
{
    for ($attempt = 1; ; $attempt++) {
        $process = j03Run(['docker', 'exec', $container, 'curl', '-sS', '--max-time', '20', '-D', '/tmp/headers', '-o', '/tmp/body', '-w', '%{http_code}', ...$arguments]);
        $status = (int) trim($process->getOutput());
        if (($status === 200 && $process->isSuccessful()) || $attempt >= $attempts) {
            break;
        }
        usleep(1_000_000);
    }

    return [
        'status' => $status,
        'headers' => j03Run(['docker', 'exec', $container, 'cat', '/tmp/headers'])->getOutput(),
        'body' => j03Run(['docker', 'exec', $container, 'cat', '/tmp/body'])->getOutput(),
    ];
}

it('installs behind Traefik on a shared network, joining only the app and trusting exactly Traefik', function (): void {
    $network = $this->proxy['network'];
    $traefik = $this->proxy['container'];
    $started = microtime(true);
    $process = $this->journey->install($this->dir, [
        '--behind-proxy', 'traefik', '--proxy-network', $network, '--url', 'https://'.J03_HOST,
        '--org', 'Proxy Org', '--owner-email', 'owner@example.test', '--locale', 'en', '--secrets-file', $this->journey->work.'/owner-link.json', '--json',
    ]);
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaJ03Uuid'] = InstanceJourney::uuid($this->dir);
    $state = json_decode((string) @file_get_contents($this->dir.'/state.json'), true);
    $project = $state['project'] ?? '';
    $attached = array_values(array_filter(explode(' ', trim(j03Run(['docker', 'network', 'inspect', '-f', '{{range .Containers}}{{.Name}} {{end}}', $network])->getOutput()))));
    $env = (string) @file_get_contents($this->dir.'/env/app.env');
    $traefikAddress = j03Address($traefik, $network);
    $GLOBALS['fsaJ03Project'] = $project;
    $this->journey->evidence('J03-install.json', ['command' => 'install.sh --dir <work>/fsa-test-j03 --version '.ReleaseHarness::version()." --behind-proxy traefik --proxy-network {$network} --url https://".J03_HOST.' --org "Proxy Org" --owner-email owner@example.test --locale en --secrets-file <work>/owner-link.json --json',
        'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $started, 1), 'stdout' => $json, 'traefik_address' => $traefikAddress, 'attached_to_shared_network' => $attached], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data'])->toMatchArray(['mode' => 'proxy', 'url' => 'https://'.J03_HOST, 'port' => null])
        ->and($json['data']['proxy'])->toBe(['setup' => 'traefik', 'network' => $network, 'container' => $traefik, 'trusted' => [$traefikAddress]])
        ->and($traefikAddress)->toMatch('/^\d+\.\d+\.\d+\.\d+$/')
        ->and($env)->toContain("\nTRUSTED_PROXIES='{$traefikAddress}'\n")
        ->and($attached)->toEqualCanonicalizing([$traefik, $project.'-app-1'])
        ->and(trim(j03Run(['docker', 'port', $project.'-app-1'])->getOutput()))->toBe('')
        ->and(trim(j03Run(['docker', 'port', $project.'-mailpit-1', '8025'])->getOutput()))->toStartWith('127.0.0.1:')
        ->and(trim(j03Run(['docker', 'network', 'inspect', '-f', '{{json .Labels}}', $network])->getOutput()))->not->toContain('app.form-schema.instance');
});

it('answers on the configured host through Traefik over TLS and honours the forwarded scheme and client', function (): void {
    $network = $this->proxy['network'];
    $client = j03Container($this->journey, 'client', $network);
    $agent = 'fsa-test-j03-proxied-'.bin2hex(random_bytes(4));
    $response = j03Curl($client, [
        '--insecure', '--connect-to', J03_HOST.':443:'.$this->proxy['container'].':443', '-A', $agent, '-H', 'X-Forwarded-For: 198.51.100.7',
        'https://'.J03_HOST.'/admin/login',
    ], 30);
    $clientAddress = j03Address($client, $network);
    $seen = j03SessionAddresses($GLOBALS['fsaJ03Project'], $agent);
    $this->journey->evidence('J03-through-traefik.json', ['request' => 'GET https://'.J03_HOST.'/admin/login via Traefik (client sends a forged X-Forwarded-For: 198.51.100.7)',
        'status' => $response['status'], 'headers' => j03RedactedHeaders($response['headers']), 'client_address' => $clientAddress, 'session_ip_addresses' => $seen], 'M10');

    expect($response['status'])->toBe(200, $response['headers'].substr($response['body'], 0, 500))
        ->and(strtolower($response['headers']))->toContain('strict-transport-security: max-age=')
        ->and($response['headers'])->toMatch('/set-cookie: __Host-[^=]+=[^\r\n]*; secure/i')
        ->and($response['body'])->toContain('https://'.J03_HOST.'/')
        ->and($response['body'])->not->toContain('http://'.J03_HOST.'/')
        ->and($seen)->toBe([$clientAddress]);
});

it('does not trust forged X-Forwarded-* from a neighbour container on the same shared network', function (): void {
    $network = $this->proxy['network'];
    $neighbour = j03Container($this->journey, 'neighbour', $network);
    $app = j03Address($GLOBALS['fsaJ03Project'].'-app-1', $network);
    $agent = 'fsa-test-j03-neighbour-'.bin2hex(random_bytes(4));
    $response = j03Curl($neighbour, [
        '-A', $agent, '-H', 'Host: '.J03_HOST, '-H', 'X-Forwarded-For: 203.0.113.66', '-H', 'X-Forwarded-Proto: https',
        '-H', 'X-Forwarded-Host: evil.example', '-H', 'X-Forwarded-Port: 443', "http://{$app}:8080/admin/login",
    ]);
    $neighbourAddress = j03Address($neighbour, $network);
    $seen = j03SessionAddresses($GLOBALS['fsaJ03Project'], $agent);
    $this->journey->evidence('J03-forged-neighbour.json', ['request' => "GET http://<app on {$network}>:8080/admin/login with X-Forwarded-For: 203.0.113.66, -Proto: https, -Host: evil.example",
        'status' => $response['status'], 'neighbour_address' => $neighbourAddress, 'session_ip_addresses' => $seen], 'M10');

    // A trusted X-Forwarded-Host would have been rejected as an unknown host (400); the session row shows the TCP peer.
    expect($response['status'])->toBe(200, $response['headers'])
        ->and($seen)->toBe([$neighbourAddress])
        ->and($seen)->not->toContain('203.0.113.66')
        ->and($response['body'])->not->toContain('evil.example');
});

it('runs the release stack file with plain docker compose and only environment variables', function (): void {
    $stackDir = $this->journey->work.'/stack';
    @mkdir($stackDir, 0755, true);
    copy($this->journey->release->directory.'/'.ReleaseHarness::STACK_FILE, $stackDir.'/'.ReleaseHarness::STACK_FILE);
    $project = 'fsa-test-stack-'.$GLOBALS['fsaJ03Id'];
    // Only environment variables configure it: the optional ones and Compose's own are unset (false), so nothing
    // from the calling shell leaks in; there is no .env next to the file.
    $env = [
        ...array_fill_keys(['COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'COMPOSE_PROFILES', 'COMPOSE_ENV_FILES', 'DOCKER_DEFAULT_PLATFORM', 'TRUSTED_PROXIES', 'TRUSTED_HOSTS',
            'MAIL_HOST', 'MAIL_PORT', 'MAIL_SCHEME', 'MAIL_REQUIRE_TLS', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'], false),
        ...$this->journey->release->platformEnvironment(),
        'FIELDSET_REGISTRY' => $this->journey->harness->registry(), 'APP_URL' => 'https://stack.fsa-test.example',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_PASSWORD' => bin2hex(random_bytes(16)),
        'REDIS_PASSWORD' => bin2hex(random_bytes(16)), 'MAILPIT_UI_PASSWORD' => bin2hex(random_bytes(8)),
        'APP_PORT' => (string) j03FreePort(), 'MAILPIT_UI_PORT' => (string) j03FreePort(),
    ];
    $compose = ['docker', 'compose', '--project-name', $project, '--file', $stackDir.'/'.ReleaseHarness::STACK_FILE];
    $started = microtime(true);

    try {
        $up = new Process([...$compose, 'up', '--detach', '--wait', '--wait-timeout', '900'], $stackDir, $env);
        $up->setTimeout(1200)->run();
        $seconds = round(microtime(true) - $started, 1);
        $smoke = j03Run(['docker', 'run', '--rm', '--network', $project.'_default', '--entrypoint', 'curl', $this->journey->release->appReference,
            '-fsS', '--max-time', '30', '-H', 'Host: localhost', '-o', '/dev/null', '-o', '/dev/null', '-w', '%{http_code} ', 'http://app:8080/up', 'http://app:8080/admin/login'], [...getenv(), ...$this->journey->release->platformEnvironment()]);
        $published = InstanceJourney::http((int) $env['APP_PORT'])->get('/up')->getStatusCode();
        $install = new Process([...$compose, 'exec', '-T', 'app', 'php', 'artisan', 'app:install', '--organization=Stack Org', '--name=Stack Owner', '--email=stack@example.test', '--owner-link', '--json'], $stackDir, $env);
        $install->setTimeout(300)->run();
        $lines = explode("\n", trim($install->getOutput()));
        $installed = json_decode(end($lines), true);
        $this->journey->evidence('J03-stack-file.json', ['command' => 'docker compose --project-name fsa-test-stack-<id> --file compose.stack.yaml up --detach --wait (environment variables only: FIELDSET_REGISTRY, APP_URL, APP_KEY, DB_PASSWORD, REDIS_PASSWORD, MAILPIT_UI_PASSWORD, APP_PORT, MAILPIT_UI_PORT)',
            'exit_code' => $up->getExitCode(), 'seconds' => $seconds, 'smoke' => trim($smoke->getOutput()), 'published_up' => $published, 'app_install' => ['exit_code' => $install->getExitCode(), 'status' => $installed['status'] ?? null]], 'M10');

        expect($up->getExitCode())->toBe(0, $up->getErrorOutput())
            ->and(trim($smoke->getOutput()))->toBe('200 200', $smoke->getErrorOutput())
            ->and($published)->toBe(200)
            ->and($install->getExitCode())->toBe(0, $install->getErrorOutput().$install->getOutput())
            ->and($installed['status'] ?? null)->toBe('ok');
    } finally {
        new Process([...$compose, 'down', '--volumes', '--remove-orphans', '--timeout', '10'], $stackDir, $env)->setTimeout(600)->run();
    }

    expect(trim(j03Run(['docker', 'ps', '-a', '--filter', 'label=com.docker.compose.project='.$project, '--format', '{{.Names}}'])->getOutput()))->toBe('');
});

it('uninstalls without touching the proxy or its network', function (): void {
    $uuid = $GLOBALS['fsaJ03Uuid'];
    $network = $this->proxy['network'];
    foreach (['client', 'neighbour'] as $role) {
        j03Run(['docker', 'rm', '-f', j03Name($role)]);
    }
    $process = $this->journey->fsa($this->dir, ['uninstall', '--yes', '--json']);
    $attached = array_values(array_filter(explode(' ', trim(j03Run(['docker', 'network', 'inspect', '-f', '{{range .Containers}}{{.Name}} {{end}}', $network])->getOutput()))));
    $this->journey->evidence('J03-uninstall.json', ['command' => 'fsa uninstall --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => InstanceJourney::json($process), 'labelled_after' => InstanceJourney::labelled($uuid), 'attached_after' => $attached], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($uuid))->toBe(['containers' => [], 'volumes' => [], 'networks' => []])
        ->and($attached)->toBe([$this->proxy['container']])
        ->and(trim(j03Run(['docker', 'inspect', '-f', '{{.State.Running}}', $this->proxy['container']])->getOutput()))->toBe('true');
});
