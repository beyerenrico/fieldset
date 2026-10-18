<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J4 (BUILD_PROMPT_V2 §11, plan U12, AE1 as amended by R59, AE9; ADR 0080): capture mode on a real local install.
 * install.sh → an invitation goes through the queue into the catcher (read with the login from
 * `fsa config show --catcher-login`) → admin pages show the full banner for the installed organization and the narrower
 * one for an organization with its own SMTP server, the command only to the operator (HTTP, then Playwright with axe)
 * → `fsa config set mail smtp` against a second Mailpit standing in as the real SMTP server, the password on stdin →
 * the test mail and the next invitation arrive there, the catcher keeps its mail and receives nothing → the catcher
 * container and volume go only with `--remove-catcher` → `fsa config set mail capture` brings it back. Finally a second
 * instance installs straight into SMTP mode (`--smtp-*`, the password from a file, ADR 0080 amendment): no catcher,
 * and the smoke test's mail (R11) arrives at a stand-in Mailpit attached to the instance's network while install.sh
 * runs. Every Docker resource is named or labelled `fsa-test-…` and removed afterwards.
 */

const J04_OWNER = 'owner@example.test';
const J04_SMTP_USER = 'fsa-test-relay-user';
const J04_SMTP_PASSWORD = 'fsa-test-relay-secret-4711';

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fsaJ04'] = new InstanceJourney($harness, $release, $harness->directory.'/j04');
        $GLOBALS['fsaJ04Smtp'] = 'fsa-test-j04-smtp-'.bin2hex(random_bytes(2));
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ04Error'] = $exception;
    }
});

afterAll(function (): void {
    // The stand-ins first: attached to an instance's default network, they would keep that network from being removed.
    foreach (['fsaJ04Smtp', 'fsaJ04Relay'] as $standIn) {
        if (isset($GLOBALS[$standIn])) {
            j04Docker(['rm', '-f', '-v', $GLOBALS[$standIn]]);
        }
    }
    if (isset($GLOBALS['fsaJ04'])) {
        InstanceJourney::cleanup($GLOBALS['fsaJ04']->work.'/fsa-test-j04', $GLOBALS['fsaJ04Uuid'] ?? null);
        InstanceJourney::cleanup($GLOBALS['fsaJ04']->work.'/fsa-test-j04-smtp', $GLOBALS['fsaJ04SmtpUuid'] ?? null);
    }
    unset($GLOBALS['fsaJ04'], $GLOBALS['fsaJ04Error'], $GLOBALS['fsaJ04Uuid'], $GLOBALS['fsaJ04Port'], $GLOBALS['fsaJ04MailPort'],
        $GLOBALS['fsaJ04Catcher'], $GLOBALS['fsaJ04Setup'], $GLOBALS['fsaJ04Smtp'], $GLOBALS['fsaJ04SmtpPort'], $GLOBALS['fsaJ04Relay'], $GLOBALS['fsaJ04SmtpUuid']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ04Error'])) {
        throw $GLOBALS['fsaJ04Error'];
    }
    $this->journey = $GLOBALS['fsaJ04'];
    $this->dir = $this->journey->work.'/fsa-test-j04';
});

/** @param  list<string>  $command */
function j04Docker(array $command): Process
{
    $process = new Process(['docker', ...$command]);
    $process->setTimeout(300);
    $process->run();

    return $process;
}

/** The running container of an instance service (by the role label), or ''. */
function j04Container(string $role): string
{
    return trim(j04Docker(['ps', '-q', '--filter', 'label=app.form-schema.instance='.$GLOBALS['fsaJ04Uuid'], '--filter', 'label=app.form-schema.role='.$role])->getOutput());
}

/**
 * Runs the harness helper in the app container (copied anew: the container is recreated by every mail change).
 *
 * @param  list<string>  $arguments
 * @return array<string, mixed>
 */
function j04Helper(array $arguments): array
{
    $app = j04Container('app');
    j04Docker(['cp', TestImage::repository().'/tests/Install/Harness/capture-banner.php', "{$app}:/tmp/capture-banner.php"]);
    $process = j04Docker(['exec', $app, 'php', '/tmp/capture-banner.php', ...$arguments]);
    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $decoded = $lines === [] ? null : json_decode($lines[array_key_last($lines)], true);

    return is_array($decoded) ? $decoded : ['error' => $process->getErrorOutput().$process->getOutput()];
}

/**
 * Mailpit's message list (API v1) on a loopback port.
 *
 * @param  array{0: string, 1: string}|null  $auth
 * @return array{status: int, messages: list<array<string, mixed>>}
 */
function j04Messages(int $port, ?array $auth = null): array
{
    $response = new Client(['http_errors' => false, 'timeout' => 10])->get("http://localhost:{$port}/api/v1/messages", $auth === null ? [] : ['auth' => $auth]);
    $json = json_decode((string) $response->getBody(), true);

    return ['status' => $response->getStatusCode(), 'messages' => is_array($json['messages'] ?? null) ? $json['messages'] : []];
}

/**
 * Waits up to `$seconds` for a message to `$to` (subject containing `$subject` when given).
 *
 * @param  array{0: string, 1: string}|null  $auth
 * @return array<string, mixed>|null
 */
function j04AwaitMessage(int $port, ?array $auth, string $to, int $seconds, ?string $subject = null): ?array
{
    $until = microtime(true) + $seconds;
    do {
        foreach (j04Messages($port, $auth)['messages'] as $message) {
            $recipients = array_map(static fn (mixed $r): string => is_array($r) ? strtolower((string) ($r['Address'] ?? '')) : '', is_array($message['To'] ?? null) ? $message['To'] : []);
            if (in_array($to, $recipients, true) && ($subject === null || str_contains((string) ($message['Subject'] ?? ''), $subject))) {
                return $message;
            }
        }
        usleep(1_000_000);
    } while (microtime(true) < $until);

    return null;
}

/** The admin page of an organization for a session cookie, as a browser gets it. */
function j04AdminPage(string $slug, array $cookie): array
{
    $response = InstanceJourney::http($GLOBALS['fsaJ04Port'])->get('/admin/'.$slug, ['headers' => ['Cookie' => $cookie['name'].'='.$cookie['value'], 'Accept' => 'text/html']]);

    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
}

it('installs a local instance in capture mode', function (): void {
    $process = $this->journey->install($this->dir, ['--org', 'Capture Org', '--owner-email', J04_OWNER, '--owner-name', 'Cora Owner', '--locale', 'en', '--secrets-file', $this->journey->work.'/owner-link.json', '--json']);
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaJ04Uuid'] = InstanceJourney::uuid($this->dir);
    $GLOBALS['fsaJ04Port'] = $json['data']['port'] ?? null;
    $GLOBALS['fsaJ04MailPort'] = (int) parse_url((string) ($json['data']['mail']['catcher_url'] ?? ''), PHP_URL_PORT);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['mail'])->toMatchArray(['mode' => 'capture'])
        ->and($GLOBALS['fsaJ04MailPort'])->toBeGreaterThan(0);
});

it('catches an invitation in the catcher, read with the operator\'s catcher login', function (): void {
    $file = $this->journey->work.'/catcher-login.json';
    $show = $this->journey->fsa($this->dir, ['config', 'show', '--catcher-login', '--secrets-file', $file, '--json']);
    $password = (string) (json_decode((string) @file_get_contents($file), true)['secrets'][0]['value'] ?? '');
    $GLOBALS['fsaJ04Catcher'] = ['fsa', $password];
    $anonymous = j04Messages($GLOBALS['fsaJ04MailPort']);
    $setup = j04Helper(['setup', J04_OWNER]);
    $GLOBALS['fsaJ04Setup'] = $setup;
    $invitation = j04AwaitMessage($GLOBALS['fsaJ04MailPort'], $GLOBALS['fsaJ04Catcher'], 'invitee@example.test', 120);
    $smokeMail = j04AwaitMessage($GLOBALS['fsaJ04MailPort'], $GLOBALS['fsaJ04Catcher'], J04_OWNER, 10, 'test mail'); // the install smoke test's (R11)
    $this->journey->evidence('J04-catcher.json', [
        'command' => 'fsa config show --catcher-login --secrets-file <work>/catcher-login.json --json', 'exit_code' => $show->getExitCode(), 'stdout' => InstanceJourney::json($show),
        'catcher_without_login' => $anonymous['status'], 'invitation' => $invitation === null ? null : ['subject' => $invitation['Subject'] ?? null, 'to' => $invitation['To'] ?? null],
        'install_test_mail' => $smokeMail === null ? null : ['subject' => $smokeMail['Subject'] ?? null, 'message_id' => $smokeMail['MessageID'] ?? null],
    ]);

    expect($show->getExitCode())->toBe(0, $show->getOutput())
        ->and($password)->not->toBe('')
        ->and($show->getOutput().$show->getErrorOutput())->not->toContain($password)
        ->and(substr(sprintf('%o', fileperms($file)), -3))->toBe('600')
        ->and($anonymous['status'])->toBe(401)
        ->and($setup)->toHaveKeys(['org_a', 'org_b', 'cookies'])
        ->and($invitation)->not->toBeNull('no invitation reached the catcher')
        ->and($smokeMail)->not->toBeNull('the install smoke test\'s mail is not in the catcher');
});

it('shows each organization its banner variant and only the operator the command (AE1, R59)', function (): void {
    $setup = $GLOBALS['fsaJ04Setup'];
    $adminA = j04AdminPage($setup['org_a'], $setup['cookies']['admin_a']);
    $operator = j04AdminPage($setup['org_a'], $setup['cookies']['operator']);
    $adminB = j04AdminPage($setup['org_b'], $setup['cookies']['admin_b']);
    $variant = static fn (string $body): ?string => preg_match('/data-capture-banner="([a-z]+)"/', $body, $m) === 1 ? $m[1] : null;
    $this->journey->evidence('J04-banner.json', [
        'org_admin_instance_mailer' => ['status' => $adminA['status'], 'variant' => $variant($adminA['body']), 'ask_owner' => str_contains($adminA['body'], 'data-capture-banner-ask'), 'mail_settings_link' => str_contains($adminA['body'], 'data-capture-banner-settings'), 'command' => str_contains($adminA['body'], 'data-capture-banner-command')],
        'operator_instance_mailer' => ['status' => $operator['status'], 'variant' => $variant($operator['body']), 'command' => str_contains($operator['body'], 'fsa config set mail smtp')],
        'org_admin_own_smtp' => ['status' => $adminB['status'], 'variant' => $variant($adminB['body']), 'mail_settings_link' => str_contains($adminB['body'], 'data-capture-banner-settings')],
    ]);

    expect($adminA['status'])->toBe(200)
        ->and($variant($adminA['body']))->toBe('full')
        ->and($adminA['body'])->toContain('Ask your instance owner')->toContain('data-capture-banner-settings')->not->toContain('data-capture-banner-command')
        ->and($operator['status'])->toBe(200)
        ->and($variant($operator['body']))->toBe('full')
        ->and($operator['body'])->toContain('data-capture-banner-command')->toContain('fsa config set mail smtp')
        ->and($adminB['status'])->toBe(200)
        ->and($variant($adminB['body']))->toBe('narrow')
        ->and($adminB['body'])->toContain('Invitations and password resets are captured')->not->toContain('data-capture-banner-settings');
});

it('passes the axe checks in a browser for both variants', function (): void {
    $playwright = TestImage::repository().'/node_modules/.bin/playwright';
    if (! is_file($playwright)) {
        expect(getenv('FSA_REQUIRE_PLAYWRIGHT'))->not->toBe('1', 'Playwright is not installed (npm ci)');
        $this->markTestSkipped('Playwright is not installed (npm ci).');
    }
    $setup = $GLOBALS['fsaJ04Setup'];
    $sessions = [
        ['name' => 'org admin (instance mailer)', 'path' => '/admin/'.$setup['org_a'], 'variant' => 'full', 'label' => 'Mail capture', 'cookie' => $setup['cookies']['admin_a']],
        ['name' => 'operator (instance mailer)', 'path' => '/admin/'.$setup['org_a'], 'variant' => 'full', 'label' => 'Mail capture', 'cookie' => $setup['cookies']['operator']],
        ['name' => 'org admin (own SMTP)', 'path' => '/admin/'.$setup['org_b'], 'variant' => 'narrow', 'label' => 'Mail capture', 'cookie' => $setup['cookies']['admin_b']],
    ];
    $process = new Process([$playwright, 'test', 'tests/e2e/fsa-capture-banner.spec.ts', '--project=desktop', '--no-deps', '--reporter=line'], TestImage::repository(), [
        'E2E_BASE_URL' => 'http://localhost:'.$GLOBALS['fsaJ04Port'], 'FSA_CAPTURE_SESSIONS' => json_encode($sessions, JSON_THROW_ON_ERROR),
        'PLAYWRIGHT_BROWSERS_PATH' => TestImage::repository().'/storage/playwright',
    ]);
    $process->setTimeout(300);
    $process->run();
    $this->journey->evidence('J04-axe.json', ['command' => 'playwright test tests/e2e/fsa-capture-banner.spec.ts --project=desktop --no-deps', 'exit_code' => $process->getExitCode(), 'output' => array_slice(explode("\n", trim($process->getOutput())), -8)]);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain('3 passed');
});

it('switches to SMTP with a stand-in Mailpit, the password on stdin, and the test mail arrives there', function (): void {
    $state = json_decode((string) file_get_contents($this->dir.'/state.json'), true);
    $smtp = $GLOBALS['fsaJ04Smtp'];
    $started = j04Docker(['run', '-d', '--name', $smtp, '--label', 'app.form-schema.test=j04', '-e', 'MP_SMTP_AUTH_ACCEPT_ANY=1', '-e', 'MP_SMTP_AUTH_ALLOW_INSECURE=1', '-p', '127.0.0.1::8025', $state['digests']['mailpit']]);
    $connected = j04Docker(['network', 'connect', $state['project'].'_default', $smtp]);
    $GLOBALS['fsaJ04SmtpPort'] = (int) (explode(':', trim(explode("\n", trim(j04Docker(['port', $smtp, '8025/tcp'])->getOutput()))[0]))[1] ?? 0);
    $began = microtime(true);
    $process = $this->journey->fsa($this->dir, ['config', 'set', 'mail', 'smtp', '--smtp-host', $smtp, '--smtp-port', '1025', '--smtp-username', J04_SMTP_USER, '--password-stdin',
        '--from-address', 'forms@example.test', '--test-to', 'ops@example.test', '--json'], [], J04_SMTP_PASSWORD."\n");
    $json = InstanceJourney::json($process);
    $test = j04AwaitMessage($GLOBALS['fsaJ04SmtpPort'], null, 'ops@example.test', 30, 'test mail');
    $this->journey->evidence('J04-config-set-mail-smtp.json', ['command' => 'printf … | fsa config set mail smtp --smtp-host fsa-test-j04-smtp-… --smtp-port 1025 --smtp-username '.J04_SMTP_USER.' --password-stdin --from-address forms@example.test --test-to ops@example.test --json',
        'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $began, 1), 'stdout' => $json, 'stderr_ndjson' => array_values(array_filter(explode("\n", $process->getErrorOutput())))]);

    expect($started->getExitCode())->toBe(0, $started->getErrorOutput())
        ->and($connected->getExitCode())->toBe(0, $connected->getErrorOutput())
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['mail'])->toMatchArray(['mode' => 'smtp', 'catcher' => 'kept'])
        ->and($json['data']['mail']['captured']['invitations'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain(J04_SMTP_PASSWORD)->not->toContain(J04_SMTP_USER)
        ->and((string) file_get_contents($this->dir.'/state.json'))->not->toContain(J04_SMTP_PASSWORD)
        ->and(substr(sprintf('%o', fileperms($this->dir.'/env/app.env')), -3))->toBe('600')
        ->and($test)->not->toBeNull('the test mail did not reach the stand-in SMTP server')
        ->and(j04Container('mailpit'))->not->toBe('');
});

it('delivers the next invitation through the new server; the kept catcher receives nothing and the banner is gone', function (): void {
    $invited = j04Helper(['invite', J04_OWNER, 'second-invitee@example.test']);
    $delivered = j04AwaitMessage($GLOBALS['fsaJ04SmtpPort'], null, 'second-invitee@example.test', 120);
    $caught = j04Messages($GLOBALS['fsaJ04MailPort'], $GLOBALS['fsaJ04Catcher']);
    $page = j04AdminPage($GLOBALS['fsaJ04Setup']['org_a'], $GLOBALS['fsaJ04Setup']['cookies']['admin_a']);
    $recipients = array_merge(...array_map(static fn (array $m): array => array_map(static fn (array $r): string => strtolower((string) ($r['Address'] ?? '')), $m['To'] ?? []), $caught['messages']));

    expect($invited)->toBe(['invited' => 'second-invitee@example.test'])
        ->and($delivered)->not->toBeNull('the invitation did not reach the new SMTP server')
        ->and($caught['status'])->toBe(200)
        ->and($recipients)->toContain('invitee@example.test')->not->toContain('second-invitee@example.test')
        ->and($page['status'])->toBe(200)
        ->and($page['body'])->not->toContain('data-capture-banner');
});

it('removes the catcher container and its volume only with --remove-catcher', function (): void {
    $state = json_decode((string) file_get_contents($this->dir.'/state.json'), true);
    $volume = $state['project'].'_mailpit-data';
    $before = InstanceJourney::labelled($GLOBALS['fsaJ04Uuid']);
    $process = $this->journey->fsa($this->dir, ['config', 'set', 'mail', 'smtp', '--remove-catcher', '--json']);
    $json = InstanceJourney::json($process);
    $after = InstanceJourney::labelled($GLOBALS['fsaJ04Uuid']);
    $login = $this->journey->fsa($this->dir, ['config', 'show', '--catcher-login', '--json']);
    $this->journey->evidence('J04-remove-catcher.json', ['command' => 'fsa config set mail smtp --remove-catcher --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'volumes_before' => $before['volumes'], 'volumes_after' => $after['volumes']]);

    expect($before['volumes'])->toContain($volume)
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['mail'])->toMatchArray(['catcher' => 'removed', 'catcher_volume_removed' => $volume])
        ->and(j04Container('mailpit'))->toBe('')
        ->and($after['volumes'])->not->toContain($volume)
        ->and($login->getExitCode())->toBe(2)
        ->and(InstanceJourney::json($login)['errors'][0]['code'] ?? null)->toBe('no_catcher');
});

it('switches back to capture mode: the catcher returns and so does the banner', function (): void {
    $process = $this->journey->fsa($this->dir, ['config', 'set', 'mail', 'capture', '--json']);
    $json = InstanceJourney::json($process);
    $page = j04AdminPage($GLOBALS['fsaJ04Setup']['org_a'], $GLOBALS['fsaJ04Setup']['cookies']['admin_a']);
    $this->journey->evidence('J04-config-set-mail-capture.json', ['command' => 'fsa config set mail capture --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['mail'])->toMatchArray(['mode' => 'capture', 'catcher' => 'active'])
        ->and(j04Container('mailpit'))->not->toBe('')
        ->and($page['body'])->toContain('data-capture-banner="full"');
});

it('refuses an SMTP password as a flag value with exit 2 through the shim (AE9)', function (): void {
    $process = $this->journey->fsa($this->dir, ['config', 'set', 'mail', 'smtp', '--smtp-host', 'smtp.example.test', '--smtp-password='.J04_SMTP_PASSWORD, '--json']);
    $json = InstanceJourney::json($process);

    expect($process->getExitCode())->toBe(2)
        ->and($json['errors'][0]['code'] ?? null)->toBe('secret_in_argument')
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain(J04_SMTP_PASSWORD);
});

/**
 * While install.sh runs: starts the stand-in SMTP server once the state names the release's Mailpit image, and attaches
 * it to the instance's default network once Compose created it (the smoke test sends its mail much later).
 *
 * @param  array{port: int, connected: int|null}  $relay
 * @return array{port: int, connected: int|null}
 */
function j04AttachRelay(string $dir, string $name, array $relay): array
{
    $state = json_decode((string) @file_get_contents($dir.'/state.json'), true);
    if ($relay['port'] === 0 && is_string($state['digests']['mailpit'] ?? null)) {
        j04Docker(['run', '-d', '--name', $name, '--label', 'app.form-schema.test=j04', '-e', 'MP_SMTP_AUTH_ACCEPT_ANY=1', '-e', 'MP_SMTP_AUTH_ALLOW_INSECURE=1', '-p', '127.0.0.1::8025', $state['digests']['mailpit']]);
        $relay['port'] = (int) (explode(':', trim(explode("\n", trim(j04Docker(['port', $name, '8025/tcp'])->getOutput()))[0]))[1] ?? 0);
    }
    $network = is_string($state['project'] ?? null) ? $state['project'].'_default' : null;
    if ($relay['port'] > 0 && $relay['connected'] === null && $network !== null && j04Docker(['network', 'inspect', $network])->isSuccessful()) {
        $relay['connected'] = j04Docker(['network', 'connect', $network, $name])->getExitCode();
    }

    return $relay;
}

/** Instance files (not the env files, where the account belongs) that contain the text. */
function j04FilesContaining(string $dir, string $text): array
{
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = (string) $file;
        if ($file->isFile() && ! str_contains($path, '/env/') && is_readable($path) && str_contains((string) file_get_contents($path), $text)) {
            $found[] = substr($path, strlen($dir) + 1);
        }
    }

    return $found;
}

it('installs straight into SMTP mode with --smtp-* and a password file: no catcher, the smoke test mail at the stand-in', function (): void {
    // One instance at a time keeps the journey inside the host's means: the capture instance (and its stand-in) go first.
    j04Docker(['rm', '-f', '-v', $GLOBALS['fsaJ04Smtp'] ?? 'fsa-test-j04-none']);
    InstanceJourney::cleanup($this->dir, $GLOBALS['fsaJ04Uuid'] ?? null);
    $dir = $this->journey->work.'/fsa-test-j04-smtp';
    $GLOBALS['fsaJ04Relay'] = 'fsa-test-j04-relay-'.bin2hex(random_bytes(2));
    $passwordFile = $this->journey->work.'/smtp-password';
    file_put_contents($passwordFile, J04_SMTP_PASSWORD."\n");
    chmod($passwordFile, 0600);
    $flags = ['--org', 'SMTP Org', '--owner-email', J04_OWNER, '--locale', 'en', '--smtp-host', $GLOBALS['fsaJ04Relay'], '--smtp-port', '1025',
        '--smtp-username', J04_SMTP_USER, '--password-file', $passwordFile, '--from-address', 'forms@example.test', '--secrets-file', $this->journey->work.'/smtp-owner-link.json', '--json'];
    $began = microtime(true);
    $process = $this->journey->installProcess($dir, $flags);
    $process->start();
    $relay = ['port' => 0, 'connected' => null];
    while ($process->isRunning()) {
        $relay = j04AttachRelay($dir, $GLOBALS['fsaJ04Relay'], $relay);
        usleep(500_000);
    }
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaJ04SmtpUuid'] = InstanceJourney::uuid($dir);
    $test = $relay['port'] > 0 ? j04AwaitMessage($relay['port'], null, J04_OWNER, 30, 'test mail') : null;
    $catchers = trim(j04Docker(['ps', '-aq', '--filter', 'label=app.form-schema.instance='.$GLOBALS['fsaJ04SmtpUuid'], '--filter', 'label=app.form-schema.role=mailpit'])->getOutput());
    $show = InstanceJourney::json($this->journey->fsa($dir, ['config', 'show', '--json']));
    $status = InstanceJourney::json($this->journey->fsa($dir, ['status', '--json']));
    $this->journey->evidence('J04-install-smtp.json', ['command' => 'install.sh --dir <work>/fsa-test-j04-smtp … --smtp-host fsa-test-j04-relay-… --smtp-port 1025 --smtp-username '.J04_SMTP_USER.' --password-file <work>/smtp-password --from-address forms@example.test --json',
        'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $began, 1), 'stdout' => $json, 'relay_connected' => $relay['connected'],
        'test_mail_at_stand_in' => $test === null ? null : ['subject' => $test['Subject'] ?? null, 'to' => $test['To'] ?? null], 'config_show_mail' => $show['data']['settings']['mail'] ?? null, 'status_mail' => $status['data']['mail'] ?? null]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($relay['connected'])->toBe(0)
        ->and($json['data']['mail'])->toBe(['mode' => 'smtp', 'catcher_url' => null])
        ->and($test)->not->toBeNull('the smoke test\'s mail did not reach the stand-in SMTP server')
        ->and($catchers)->toBe('')
        ->and($show['data']['settings']['mail']['mode'] ?? null)->toBe('smtp')
        ->and($status['data']['mail']['mode'] ?? null)->toBe('smtp')
        ->and(array_keys($status['data']['services'] ?? []))->not->toContain('mailpit')
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain(J04_SMTP_PASSWORD)->not->toContain(J04_SMTP_USER)
        ->and(j04FilesContaining($dir, J04_SMTP_PASSWORD))->toBe([])
        ->and(substr(sprintf('%o', fileperms($dir.'/env/app.env')), -3))->toBe('600');
});
