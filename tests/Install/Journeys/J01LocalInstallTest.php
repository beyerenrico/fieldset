<?php

declare(strict_types=1);

use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J1 (BUILD_PROMPT_V2 §11, plan U5): a local install from the harness release with the harness build of
 * install.sh and real Docker. install.sh → fieldset install → instance up → the owner's sign-in link leads into the
 * password-set flow → fieldset status, doctor --post, logs, login-link → /up and /admin/login through the published
 * port → fieldset uninstall --yes leaves no container, volume or network with the instance label. JSON transcripts
 * go to docs/agent/milestones/evidence/M9/ (workspace paths and the registry port replaced).
 */

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fieldsetJ01'] = new InstanceJourney($harness, $release, $harness->directory.'/j01');
    } catch (Throwable $exception) {
        $GLOBALS['fieldsetJ01Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fieldsetJ01'])) {
        InstanceJourney::cleanup($GLOBALS['fieldsetJ01']->work.'/fieldset-test-j01', $GLOBALS['fieldsetJ01Uuid'] ?? null);
    }
    unset($GLOBALS['fieldsetJ01'], $GLOBALS['fieldsetJ01Error'], $GLOBALS['fieldsetJ01Uuid'], $GLOBALS['fieldsetJ01Port']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fieldsetJ01Error'])) {
        throw $GLOBALS['fieldsetJ01Error'];
    }
    $this->journey = $GLOBALS['fieldsetJ01'];
    $this->dir = $this->journey->work.'/fieldset-test-j01';
});

it('installs a local instance with install.sh and fieldset install', function (): void {
    $secrets = $this->journey->work.'/owner-link.json';
    $started = microtime(true);
    $process = $this->journey->install($this->dir, ['--org', 'Journey Org', '--owner-email', 'owner@example.test', '--owner-name', 'Jo Owner', '--locale', 'en', '--secrets-file', $secrets, '--json']);
    $json = InstanceJourney::json($process);
    $GLOBALS['fieldsetJ01Uuid'] = InstanceJourney::uuid($this->dir);
    $GLOBALS['fieldsetJ01Port'] = $json['data']['port'] ?? null;
    $state = json_decode((string) @file_get_contents($this->dir.'/state.json'), true);
    $link = json_decode((string) @file_get_contents($secrets), true)['secrets'][0] ?? [];
    $this->journey->evidence('J01-install.json', ['command' => 'install.sh --dir <work>/fieldset-test-j01 --version '.ReleaseHarness::version().' --org "Journey Org" --owner-email owner@example.test --owner-name "Jo Owner" --locale en --secrets-file <work>/owner-link.json --json', 'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $started, 1), 'stdout' => $json, 'stderr_ndjson' => array_values(array_filter(explode("\n", $process->getErrorOutput())))]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['status'])->toBe('ok')
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['version'])->toBe(ReleaseHarness::version())
        ->and($json['data']['url'])->toBe('http://localhost:'.$json['data']['port'])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and($json['secrets'])->toBe([['name' => 'sign_in_link', 'written_to' => $secrets, 'expires_at' => $json['data']['sign_in_link']['expires_at']]])
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('/login-link#')
        ->and($state['digests']['app'])->toBe($this->journey->release->appReference)
        ->and($state['history'][0]['event'])->toBe('installed')
        ->and($link['value'] ?? '')->toStartWith('http://localhost:'.$json['data']['port'].'/login-link#')
        ->and(substr(sprintf('%o', fileperms($secrets)), -3))->toBe('600')
        ->and(glob($this->dir.'/.fieldset-nonce-*'))->toBe([]);
    $GLOBALS['fieldsetJ01Link'] = $link['value'];
});

it('lets the owner link lead into the password-set flow', function (): void {
    $port = $GLOBALS['fieldsetJ01Port'];
    [, $token] = explode('#', $GLOBALS['fieldsetJ01Link'], 2);
    $http = InstanceJourney::http($port);

    $landing = $http->get('/login-link');
    preg_match('/name="_token" value="([^"]+)"/', (string) $landing->getBody(), $csrf);
    $consumed = $http->post('/login-link', ['form_params' => ['_token' => $csrf[1] ?? '', 'token' => $token]]);
    $location = $consumed->getHeaderLine('Location');
    $reset = $http->get(parse_url($location, PHP_URL_PATH).'?'.parse_url($location, PHP_URL_QUERY));
    $again = $http->post('/login-link', ['form_params' => ['_token' => $csrf[1] ?? '', 'token' => $token]]);

    expect($landing->getStatusCode())->toBe(200)
        ->and((string) $landing->getBody())->not->toContain($token)
        ->and($consumed->getStatusCode())->toBe(303)
        ->and($location)->toContain('/admin/password-reset/reset')
        ->and($location)->not->toContain($token)
        ->and($reset->getStatusCode())->toBe(200)
        ->and($again->getStatusCode())->not->toBe(303);
});

it('reports the running instance with fieldset status, doctor --post, logs and login-link', function (): void {
    $status = $this->journey->fieldset($this->dir, ['status', '--json']);
    $doctor = $this->journey->fieldset($this->dir, ['doctor', '--post', '--json']);
    $logs = $this->journey->fieldset($this->dir, ['logs', 'app', '--tail', '20', '--json']);
    $linkFile = $this->journey->work.'/admin-link.json';
    $link = $this->journey->fieldset($this->dir, ['login-link', 'owner@example.test', '--secrets-file', $linkFile, '--json']);
    $version = $this->journey->fieldset($this->dir, ['version', '--json']);
    $commands = ['status' => 'fieldset status --json', 'doctor' => 'fieldset doctor --post --json', 'login-link' => 'fieldset login-link owner@example.test --secrets-file <work>/admin-link.json --json', 'version' => 'fieldset version --json'];
    foreach (['status' => $status, 'doctor' => $doctor, 'login-link' => $link, 'version' => $version] as $name => $process) {
        $this->journey->evidence("J01-{$name}.json", ['command' => $commands[$name], 'exit_code' => $process->getExitCode(), 'stdout' => InstanceJourney::json($process)]);
    }
    $statusJson = InstanceJourney::json($status);

    expect($status->getExitCode())->toBe(0, $status->getErrorOutput().$status->getOutput())
        ->and($statusJson['data'])->toMatchArray(['installed' => true, 'health' => 'ok', 'mode' => 'local', 'url' => 'http://localhost:'.$GLOBALS['fieldsetJ01Port']])
        ->and(array_keys($statusJson['data']['services']))->toBe(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup'])
        ->and($statusJson['data']['sign_in_links'])->not->toBeNull()
        ->and($doctor->getExitCode())->toBe(0, $doctor->getOutput())
        ->and($logs->getExitCode())->toBe(0, $logs->getOutput())
        ->and(InstanceJourney::json($logs)['data']['services'])->toBe(['app'])
        ->and($link->getExitCode())->toBe(0, $link->getOutput())
        ->and($link->getOutput())->not->toContain('/login-link#')
        ->and((string) file_get_contents($linkFile))->toContain('/login-link#')
        ->and(InstanceJourney::json($version)['data']['instance'])->toBe(ReleaseHarness::version());
});

it('answers /up and the admin login through the published loopback port', function (): void {
    // Also the U10 journey assertion (ADR 0074): the app runs as www-data (UID 33) and must read runtime.json,
    // written by another owner; an unreadable file fails closed and both answers would be 503.
    $http = InstanceJourney::http($GLOBALS['fieldsetJ01Port']);

    expect($http->get('/up')->getStatusCode())->toBe(200)
        ->and($http->get('/admin/login')->getStatusCode())->toBe(200);
});

it('uninstalls without leaving a labelled container, volume or network behind', function (): void {
    $uuid = $GLOBALS['fieldsetJ01Uuid'];
    $before = InstanceJourney::labelled($uuid);
    $process = $this->journey->fieldset($this->dir, ['uninstall', '--yes', '--json']);
    $this->journey->evidence('J01-uninstall.json', ['command' => 'fieldset uninstall --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => InstanceJourney::json($process), 'labelled_before' => $before, 'labelled_after' => InstanceJourney::labelled($uuid)]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($before['containers'])->toHaveCount(8) // the backup service since U16 (ADR 0084)
        ->and($before['volumes'])->not->toBeEmpty()
        ->and(InstanceJourney::labelled($uuid))->toBe(['containers' => [], 'volumes' => [], 'networks' => []])
        ->and(array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])))->toBe([]);
});
