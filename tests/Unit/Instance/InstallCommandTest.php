<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerInfo;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Install\RecordDigest;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceState;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U5 / M9-T08: `fsa install` in local mode against scripted Docker (F2, R9–R15, R54, R63, R69; AE3, AE4, AE5,
 * AE9). The real thing runs in tests/Install/Journeys/J01LocalInstallTest.php and J12IdempotenceTest.php.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

function installOnce(InstanceCommandFixture $fixture, array $flags = []): array
{
    return $fixture->run('fsa:install', InstanceCommandFixture::installFlags($flags));
}

it('installs a local instance phase by phase and hands out the owner link only under secrets', function (): void {
    [$code, $json] = installOnce($this->fixture);
    $state = $this->fixture->state();
    $methods = $this->fixture->docker->methods();
    $history = new OperationJournal($this->fixture->directory)->history()[0];
    $install = $this->fixture->oneOffCalls('app:install')[0];

    expect($code)->toBe(0, json_encode($json))
        ->and($json['status'])->toBe('ok')
        ->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toMatchArray(['url' => 'http://localhost:8080', 'port' => 8080, 'version' => '2.0.0-alpha.1', 'mode' => 'local', 'owner_email' => 'owner@example.com'])
        ->and($json['data']['mail'])->toBe(['mode' => 'capture', 'catcher_url' => 'http://localhost:8025'])
        ->and($json['data']['sign_in_link'])->toBe(['expires_at' => '2026-10-18T11:00:00Z', 'delivered' => 'secrets'])
        ->and($json['secrets'])->toBe([['name' => 'sign_in_link', 'value' => InstanceCommandFixture::LINK, 'expires_at' => '2026-10-18T11:00:00Z']])
        ->and(json_encode([$json['data'], $json['warnings'], $json['errors']]))->not->toContain('owner-token')
        ->and($state->digests()['app'])->toBe(InstanceCommandFixture::appImage())
        ->and($state->digests()['postgres'])->toStartWith('registry.test:5000/library/postgres@sha256:')
        ->and($state->setting('port'))->toBe(8080)
        ->and($state->history()[0])->toMatchArray(['event' => 'installed', 'organization' => 'Acme Forms', 'port' => 8080, 'version' => '2.0.0-alpha.1'])
        ->and($state->history()[0]['owner_email'])->toStartWith(RecordDigest::PREFIX)
        ->and($state->history()[0]['owner_name'])->toStartWith(RecordDigest::PREFIX)
        ->and($state->toJson())->not->toContain('owner@example.com')->not->toContain('Olivia Owner')
        ->and(array_column($history->phases(), 'name'))->toBe(['doctor', 'secrets', 'state', 'pull', 'up', 'migrate', 'app_install', 'start', 'smoke', 'done'])
        ->and(json_encode($history->toArray()))->not->toContain('owner-token')->not->toContain('owner@example.com')->not->toContain('Olivia Owner')
        ->and($history->context()['owner_email'])->toBe($state->history()[0]['owner_email'])
        ->and($this->fixture->directory->exists(OperationJournal::CURRENT))->toBeFalse()
        ->and($install['service'])->toBe('app')
        ->and($install['command'])->toBe(['php', 'artisan', 'app:install', '--organization=Acme Forms', '--name=Olivia Owner', '--email=owner@example.com', '--locale=en', '--owner-link', '--actor-uid='.posix_getuid(), '--json'])
        ->and(array_column($this->fixture->docker->called('pullImage'), 'reference'))->toHaveCount(4)->not->toContain(InstanceCommandFixture::appImage())
        ->and($this->fixture->docker->called('composeUp'))->toBe([
            ['project' => $state->project(), 'services' => ['postgres', 'redis'], 'waitTimeout' => 300, 'removeOrphans' => false],
            ['project' => $state->project(), 'services' => [], 'waitTimeout' => 300, 'removeOrphans' => true],
        ])
        ->and(array_search('composeRun', $methods, true))->toBeGreaterThan(array_search('composeUp', $methods, true))
        ->and(substr(sprintf('%o', fileperms($this->fixture->directory->path('env/app.env'))), -3))->toBe('600');
});

it('runs the smoke test through the compose networks with the instance label, never the host port', function (): void {
    installOnce($this->fixture);
    $state = $this->fixture->state();
    [$app, $gotenberg] = $this->fixture->runSpecs('curl');

    expect($app->network)->toBe($state->project().'_default')
        ->and($app->image)->toBe(InstanceCommandFixture::appImage())
        ->and($app->labels)->toBe(Labels::forInstance($state->uuid(), 'smoke'))
        ->and($app->command)->toContain('Host: localhost:8080', 'http://app:8080/up', 'http://app:8080/admin/login', '--fail-early')
        ->and($gotenberg->network)->toBe($state->project().'_renderer')
        ->and($gotenberg->command)->toContain('http://gotenberg:3000/health');
});

it('changes nothing when run again with the same flags (AE3), and names fsa login-link for a sign-in link', function (): void {
    installOnce($this->fixture);
    $calls = count($this->fixture->docker->calls);
    $stateBefore = $this->fixture->state()->toJson();

    [$code, $json] = installOnce($this->fixture);
    [$bareCode, $bare] = $this->fixture->run('fsa:install');

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['secrets'])->toBe([])
        ->and($json['data']['url'])->toBe('http://localhost:8080')
        ->and($json['data']['owner_email'])->toBe('owner@example.com')
        ->and($json['data'])->toHaveKey('sign_in_link')
        ->and($json['data']['sign_in_link'])->toBeNull()
        ->and($json['warnings'][0]['code'])->toBe('no_sign_in_link')
        ->and($json['warnings'][0]['hint'])->toContain('fsa login-link owner@example.com --secrets-file')
        ->and($this->fixture->docker->methodsSince($calls))->toBe([])
        ->and($this->fixture->state()->toJson())->toBe($stateBefore)
        ->and($bareCode)->toBe(0)
        ->and($bare['changed'])->toBeFalse()
        ->and($bare['data']['owner_email'])->toBeNull()
        ->and($bare['warnings'][0]['hint'])->toContain('fsa login-link <email> --secrets-file');
});

it('compares the owner flags of a repeated install through their digests, also after a key rotation', function (): void {
    installOnce($this->fixture);
    $env = new EnvFiles($this->fixture->directory);
    $app = $env->read('app');
    $env->write('app', [...$app, 'APP_KEY' => 'base64:'.base64_encode(str_repeat('n', 32)), 'APP_PREVIOUS_KEYS' => $app['APP_KEY']]);

    [$sameCode, $same] = installOnce($this->fixture, ['--owner-email' => 'OWNER@example.com']);
    [$otherCode, $other] = installOnce($this->fixture, ['--owner-name' => 'Someone Else']);

    expect($sameCode)->toBe(0, json_encode($same))
        ->and($same['changed'])->toBeFalse()
        ->and($otherCode)->toBe(2)
        ->and($other['errors'][0]['code'])->toBe('use_config_set')
        ->and($other['errors'][0]['message'])->toContain('--owner-name');
});

it('refuses different flags on a finished instance with exit 2 and the config-set hint', function (array $flags, string $flag): void {
    installOnce($this->fixture);
    $calls = count($this->fixture->docker->calls);

    [$code, $json] = $this->fixture->run('fsa:install', $flags);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('use_config_set')
        ->and($json['errors'][0]['message'])->toContain($flag)
        ->and($json['errors'][0]['hint'])->toContain('fsa config set')->toContain('later releases')->toContain('fsa uninstall')
        ->and($this->fixture->docker->methodsSince($calls))->toBe([]);
})->with([
    'organization' => [['--org' => 'Other Org'], '--org'],
    'owner e-mail' => [['--owner-email' => 'other@example.com'], '--owner-email'],
    'port' => [['--port' => '9090'], '--port'],
    'version' => [['--release' => '2.0.0'], '--version'],
    'domain' => [['--domain' => 'forms.example.com'], '--domain'],
]);

it('takes the next free port when 8080 is taken and keeps it on a re-run (AE4)', function (): void {
    $this->fixture->takenPorts = [8080, 8025];

    [$code, $json] = installOnce($this->fixture);
    $probes = count($this->fixture->runSpecs('true'));
    [, $again] = installOnce($this->fixture);
    Artisan::call('fsa:install', ['--dir' => $this->fixture->directory->root]);
    $text = Artisan::output();

    expect($code)->toBe(0)
        ->and($json['data']['port'])->toBe(8081)
        ->and($json['data']['url'])->toBe('http://localhost:8081')
        ->and($json['data']['mail']['catcher_url'])->toBe('http://localhost:8026')
        ->and($this->fixture->state()->setting('mail_ui_port'))->toBe(8026)
        ->and(array_map(static fn ($spec): string => $spec->ports[0], $this->fixture->runSpecs('true')))->toBe(['127.0.0.1:8080:8080', '127.0.0.1:8081:8080', '127.0.0.1:8025:8080', '127.0.0.1:8026:8080'])
        ->and($this->fixture->runSpecs('true')[0]->labels)->toBe(Labels::forInstance($this->fixture->state()->uuid(), 'port-probe'))
        ->and($again['changed'])->toBeFalse()
        ->and($again['data']['port'])->toBe(8081)
        ->and(count($this->fixture->runSpecs('true')))->toBe($probes)
        ->and($text)->toContain('http://localhost:8081')->toContain('nothing changed');
});

it('fails the preflight with exit 3 when an explicit --port is taken, changing nothing', function (): void {
    $this->fixture->takenPorts = [9000];

    [$code, $json] = installOnce($this->fixture, ['--port' => '9000']);
    $ports = collect($json['data']['checks'])->firstWhere('key', 'ports');

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('doctor_failed')
        ->and($ports['status'])->toBe('fail')
        ->and($ports['summary'])->toContain('9000')
        ->and($this->fixture->directory->hasState())->toBeFalse()
        ->and($this->fixture->directory->exists('env'))->toBeFalse()
        ->and($this->fixture->directory->exists(OperationJournal::CURRENT))->toBeFalse();
});

it('exits 3 before pulling anything when the domain resolves elsewhere (AE5)', function (): void {
    $this->fixture->probes->dns['forms.example.com'] = ['a' => ['203.0.113.10'], 'aaaa' => []];
    $this->fixture->facts(['lanAddresses' => ['198.18.0.1']]);

    [$code, $json] = installOnce($this->fixture, ['--domain' => 'forms.example.com']);
    $dns = collect($json['data']['checks'])->firstWhere('key', 'dns');

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('doctor_failed')
        ->and($json['errors'][0]['hint'])->toContain('A record')
        ->and($dns['status'])->toBe('fail')
        ->and($dns['details']['a'])->toBe(['203.0.113.10'])
        ->and($this->fixture->docker->called('pullImage'))->toBe([])
        ->and($this->fixture->docker->called('composeUp'))->toBe([])
        ->and($this->fixture->directory->hasState())->toBeFalse();
});

it('exits 4 on a failed smoke test and resumes there with a fresh sign-in link', function (): void {
    $this->fixture->smokeOutcome = new ProcessOutcome(22, "200 http://app:8080/up\n", 'curl: (22) The requested URL returned error: 500');

    [$code, $json] = installOnce($this->fixture);
    $journal = new OperationJournal($this->fixture->directory)->current();
    $calls = count($this->fixture->docker->calls);
    $this->fixture->smokeOutcome = null;
    [$resumedCode, $resumed] = installOnce($this->fixture);
    $since = array_slice($this->fixture->docker->calls, $calls);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('smoke_failed')
        ->and($json['errors'][0]['hint'])->toContain('resumes at the smoke test')
        ->and($json['secrets'])->toBe([])
        ->and(json_encode($json))->not->toContain('owner-token')
        ->and($journal?->lastStartedPhase())->toBe('smoke')
        ->and($journal?->hasCompleted('app_install'))->toBeTrue()
        ->and($resumedCode)->toBe(0, json_encode($resumed))
        ->and($resumed['changed'])->toBeTrue()
        ->and($resumed['secrets'][0]['value'])->toBe(InstanceCommandFixture::ADMIN_LINK)
        ->and(array_column($since, 'method'))->not->toContain('composeUp')->not->toContain('pullImage')
        ->and(array_values(array_map(static fn (array $call): string => $call['args']['command'][2] ?? '', array_filter($since, static fn (array $call): bool => $call['method'] === 'composeRun'))))->toBe(['identity:login-link'])
        ->and($this->fixture->oneOffCalls('identity:login-link')[0]['command'])->toBe(['php', 'artisan', 'identity:login-link', '--actor-uid='.posix_getuid(), '--json', '--', 'owner@example.com'])
        ->and($this->fixture->directory->exists(OperationJournal::CURRENT))->toBeFalse();
});

it('reuses the secrets of an interrupted install and accepts corrected flags for phases not yet done', function (): void {
    $this->fixture->oneOffs['migrate'] = new ProcessOutcome(1, '', 'SQLSTATE[08006] connection refused');

    [$code, $json] = installOnce($this->fixture);
    $appKey = new EnvFiles($this->fixture->directory)->read('app')['APP_KEY'];
    unset($this->fixture->oneOffs['migrate']);
    [$conflictCode, $conflict] = installOnce($this->fixture, ['--port' => '9999']);
    [$resumedCode] = installOnce($this->fixture, ['--org' => 'Acme Corrected']);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('migrate_failed')
        ->and($json['data']['output'])->toContain('connection refused')
        ->and($conflictCode)->toBe(2)
        ->and($conflict['errors'][0]['code'])->toBe('flag_already_applied')
        ->and($conflict['errors'][0]['message'])->toContain('--port')
        ->and($resumedCode)->toBe(0)
        ->and(new EnvFiles($this->fixture->directory)->read('app')['APP_KEY'])->toBe($appKey)
        ->and($this->fixture->oneOffCalls('app:install')[0]['command'])->toContain('--organization=Acme Corrected')
        ->and($this->fixture->state()->history()[0]['organization'])->toBe('Acme Corrected');
});

it('keeps flags corrected on one resume for the next one', function (): void {
    $this->fixture->oneOffs['migrate'] = new ProcessOutcome(1, '', 'failed');
    installOnce($this->fixture);
    installOnce($this->fixture, ['--org' => 'Second Name']);
    unset($this->fixture->oneOffs['migrate']);

    [$code] = $this->fixture->run('fsa:install');

    expect($code)->toBe(0)
        ->and($this->fixture->oneOffCalls('app:install')[0]['command'])->toContain('--organization=Second Name');
});

it('exits 3 when an image cannot be pulled and resumes at the pull', function (): void {
    $this->fixture->docker->failOn('pullImage', new DockerCommandFailed('pull', new ProcessOutcome(1, '', 'manifest unknown')));

    [$code, $json] = installOnce($this->fixture);
    unset($this->fixture->docker->failures['pullImage']);
    [$resumedCode] = installOnce($this->fixture);

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('image_unavailable')
        ->and($json['errors'][0]['retryable'])->toBeTrue()
        ->and($resumedCode)->toBe(0);
});

it('exits 4 with the failing services and their last log lines when the stack does not become healthy', function (): void {
    $this->fixture->docker->failOn('composeUp', new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container postgres is unhealthy')));
    $this->fixture->docker->logs = "postgres-1 | FATAL: data directory has wrong ownership\n";
    $this->fixture->docker->beforeRun = null;

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('services_unhealthy')
        ->and($json['data']['docker'])->toContain('unhealthy');
});

it('exits 7 and names the resources when the project exists with another instance label', function (): void {
    $state = InstanceState::create('5d1c2b9e-8a4d-4c1e-9b7a-2d5e6f708199', $this->fixture->directory->root, posix_getuid(), posix_getgid(), posix_getuid());
    $this->fixture->directory->saveState($state);
    $this->fixture->docker->withRunningContainer(str_repeat('f', 64), '2026-10-18T09:00:00Z', [
        Labels::COMPOSE_PROJECT => $state->project(), Labels::INSTANCE => '00000000-0000-4000-8000-000000000000',
    ], $state->project().'-postgres-1');

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(7)
        ->and($json['errors'][0]['code'])->toBe('project_conflict')
        ->and($json['errors'][0]['message'])->toContain('container '.$state->project().'-postgres-1')
        ->and($json['data']['resources'])->toBe(['container '.$state->project().'-postgres-1'])
        ->and($this->fixture->directory->exists('env'))->toBeFalse();
});

it('warns about containers of another installation that ran from this directory', function (): void {
    $this->fixture->docker->withRunningContainer(str_repeat('e', 64), '2026-10-18T09:00:00Z', [
        Labels::INSTANCE => '11111111-1111-4111-8111-111111111111', 'com.docker.compose.project.working_dir' => $this->fixture->directory->root,
    ]);

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(0)
        ->and(collect($json['warnings'])->firstWhere('code', 'orphans_found')['hint'])
        ->toContain('Check first')
        ->toContain('docker ps -a --filter label=app.form-schema.instance=11111111-1111-4111-8111-111111111111')
        ->toContain('fsa uninstall --orphans 11111111-1111-4111-8111-111111111111');
});

it('exits 2 (not 6, which means confirmation) for missing required flags without a terminal or under --yes, and asks for them in a terminal', function (): void {
    [$code, $json] = $this->fixture->run('fsa:install', ['--owner-email' => 'owner@example.com']);
    $this->fixture->facts(['tty' => true]);
    [$yesCode] = $this->fixture->run('fsa:install', ['--yes' => true]);

    expect($code)->toBe(2)
        ->and($json['status'])->toBe('failed')
        ->and($json['errors'][0]['code'])->toBe('input_required')
        ->and($json['data']['missing'])->toBe(['--org'])
        ->and($yesCode)->toBe(2)
        ->and($this->fixture->docker->calls)->toBe([]);

    $this->artisan('fsa:install', ['--dir' => $this->fixture->directory->root, '--owner-email' => 'owner@example.com'])
        ->expectsQuestion('Organization name', 'Asked Org')
        ->assertExitCode(0);
    expect($this->fixture->oneOffCalls('app:install')[0]['command'])->toContain('--organization=Asked Org');
});

it('rejects a secret flag (AE9), malformed values and flags of later releases with exit 2', function (array $flags, string $error): void {
    [$code, $json] = installOnce($this->fixture, $flags);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and(json_encode($json))->not->toContain('hunter2')
        ->and($this->fixture->docker->calls)->toBe([]);
})->with([
    'smtp password as flag (AE9)' => [['--smtp-password' => 'hunter2'], 'secret_in_argument'],
    'locale' => [['--locale' => 'fr'], 'invalid_flag'],
    'port' => [['--port' => '70000'], 'invalid_flag'],
    'email' => [['--owner-email' => 'not-an-email'], 'invalid_flag'],
    'organization with a line break' => [['--org' => "Acme\nForms"], 'invalid_flag'],
    'version' => [['--release' => 'latest'], 'invalid_flag'],
    'domain that is an IP' => [['--domain' => '203.0.113.10'], 'invalid_flag'],
    'demo' => [['--demo' => true], 'unsupported_option'],
    'proxy setup' => [['--behind-proxy' => 'nginx'], 'invalid_flag'],
    'phone https' => [['--phone-https' => 'tunnel'], 'unsupported_option'],
]);

it('refuses a manifest of another version than --version with exit 3', function (): void {
    [$code, $json] = installOnce($this->fixture, ['--release' => '2.0.0']);

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('release_mismatch');
});

it('finds the stored release without FSA_RELEASE_MANIFEST and refuses one outside the instance directory', function (): void {
    $this->fixture->facts(['releaseManifest' => null]);
    [$stored] = installOnce($this->fixture);

    $other = new InstanceCommandFixture;
    $other->facts(['releaseManifest' => '/tmp/release-manifest.json']);
    [$outsideCode, $outside] = installOnce($other);

    expect($stored)->toBe(0)
        ->and($outsideCode)->toBe(2)
        ->and($outside['errors'][0]['code'])->toBe('manifest_outside_instance');
});

it('needs a release to install from', function (): void {
    $this->fixture->facts(['releaseManifest' => null]);
    $this->fixture->directory->files()->delete('releases');

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('manifest_required');
});

it('writes the link to --secrets-file, or hands it to the browser with --open, never to stdout', function (): void {
    $file = $this->fixture->directory->root.'/link.json';
    [$code, $json] = installOnce($this->fixture, ['--secrets-file' => $file]);

    $desktop = new InstanceCommandFixture;
    $desktop->facts(['desktop' => true, 'tty' => true, 'openUrlFile' => '.fsa-open-url-0123456789abcdef0123456789abcdef']);
    [, $browser] = installOnce($desktop, ['--open' => true]);

    expect($code)->toBe(0)
        ->and($json['secrets'])->toBe([['name' => 'sign_in_link', 'written_to' => $file, 'expires_at' => '2026-10-18T11:00:00Z']])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and(file_get_contents($file))->toContain(InstanceCommandFixture::LINK)
        ->and($browser['secrets'])->toBe([])
        ->and($browser['data']['sign_in_link']['delivered'])->toBe('browser')
        ->and($browser['data']['sign_in_link']['hint'])->toContain('fsa login-link owner@example.com --secrets-file')
        ->and(json_encode($browser))->not->toContain('owner-token')
        ->and(trim((string) $desktop->directory->read('.fsa-open-url-0123456789abcdef0123456789abcdef')))->toBe(InstanceCommandFixture::LINK);
});

it('returns the link as JSON (R12) under --json even on a desktop, and hands it to the browser only for a person at a terminal', function (): void {
    $handOver = '.fsa-open-url-0123456789abcdef0123456789abcdef';
    $this->fixture->facts(['desktop' => true, 'tty' => true, 'openUrlFile' => $handOver]);
    [, $json] = installOnce($this->fixture);

    $person = new InstanceCommandFixture;
    $person->facts(['desktop' => true, 'tty' => true, 'openUrlFile' => $handOver]);
    $exit = Artisan::call('fsa:install', ['--dir' => $person->directory->root, ...InstanceCommandFixture::installFlags()]);
    $text = Artisan::output();

    expect($json['secrets'][0]['value'])->toBe(InstanceCommandFixture::LINK)
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets')
        ->and($this->fixture->directory->exists($handOver))->toBeFalse()
        ->and($exit)->toBe(0)
        ->and($text)->toContain('"delivered":"browser"')->not->toContain('owner-token')
        ->and(trim((string) $person->directory->read($handOver)))->toBe(InstanceCommandFixture::LINK);
});

it('names fsa login-link when the secrets file cannot be written after the install', function (): void {
    $file = $this->fixture->directory->root.'/out/link.json';
    mkdir(dirname($file));
    $this->fixture->docker->beforeRun = static function () use ($file): void {
        if (is_dir(dirname($file))) {
            rmdir(dirname($file));
        }
    };

    [$code, $json] = installOnce($this->fixture, ['--secrets-file' => $file]);

    expect($code)->toBe(2)
        ->and($json['changed'])->toBeTrue()
        ->and($json['errors'][0]['code'])->toBe('secrets_file_unwritable')
        ->and($json['errors'][0]['hint'])->toContain('fsa login-link owner@example.com --secrets-file')
        ->and(json_encode($json))->not->toContain('owner-token');
});

it('issues a fresh link when app:install finds an existing installation, and records none of the unapplied app flags', function (): void {
    $this->fixture->oneOffs['app:install'] = InstanceCommandFixture::oneOffJson(['command' => 'app:install', 'status' => 'ok', 'warnings' => [['code' => 'already_installed', 'message' => 'Already installed', 'hint' => null]]]);

    [$code, $json] = installOnce($this->fixture);
    $record = $this->fixture->state()->history()[0];
    [$againCode, $again] = installOnce($this->fixture, ['--org' => 'Another Name', '--owner-email' => 'someone@example.com']);

    expect($code)->toBe(0)
        ->and($json['secrets'][0]['value'])->toBe(InstanceCommandFixture::ADMIN_LINK)
        ->and($json['warnings'][0]['code'])->toBe('app_already_installed')
        ->and($record)->not->toHaveKeys(['organization', 'owner_email', 'owner_name', 'locale'])
        ->and($record)->toMatchArray(['event' => 'installed', 'version' => '2.0.0-alpha.1', 'port' => 8080])
        ->and($againCode)->toBe(0, json_encode($again))
        ->and($again['changed'])->toBeFalse();
});

it('refuses half a secret set in env/app.env instead of generating a new APP_KEY (exit 3)', function (): void {
    new EnvFiles($this->fixture->directory)->write('app', ['APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('invalid_instance_state')
        ->and(new EnvFiles($this->fixture->directory)->read('app'))->toBe(['APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32))]);
});

it('finishes with a warning when no sign-in link can be issued', function (): void {
    $this->fixture->oneOffs['app:install'] = InstanceCommandFixture::oneOffJson(['command' => 'app:install', 'status' => 'ok']);
    $this->fixture->oneOffs['identity:login-link'] = InstanceCommandFixture::oneOffJson(['command' => 'identity:login-link', 'status' => 'failed', 'errors' => [['code' => 'rate_limited', 'message' => 'Too many links.', 'hint' => null, 'retryable' => true]]]);

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(0)
        ->and($json['secrets'])->toBe([])
        ->and($json['warnings'][0]['code'])->toBe('sign_in_link_unavailable')
        ->and($json['warnings'][0]['hint'])->toContain('fsa login-link owner@example.com');
});

it('exits 2 when app:install refuses the input and resumes with a corrected flag', function (): void {
    $this->fixture->oneOffs['app:install'] = InstanceCommandFixture::oneOffJson(['command' => 'app:install', 'status' => 'failed', 'errors' => [['code' => 'invalid_input', 'message' => 'The organization field is required.', 'hint' => null, 'retryable' => false]]]);

    [$code, $json] = installOnce($this->fixture);
    $open = $this->fixture->directory->exists(OperationJournal::CURRENT);
    unset($this->fixture->oneOffs['app:install']);
    [$retry] = installOnce($this->fixture, ['--org' => 'Fixed']);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('invalid_input')
        ->and($open)->toBeTrue()
        ->and($retry)->toBe(0)
        ->and($this->fixture->oneOffCalls('app:install')[1]['command'])->toContain('--organization=Fixed');
});

it('exits 4 when app:install prints no result', function (): void {
    $this->fixture->oneOffs['app:install'] = new ProcessOutcome(1, 'PHP Fatal error', 'boom');

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('app_install_failed')
        ->and($json['data']['code'])->toBe('one_off_failed');
});

it('exits 9 while another operation is unfinished or holds the lock', function (): void {
    new OperationJournal($this->fixture->directory)->begin('upgrade', 501);

    [$code, $json] = installOnce($this->fixture);

    expect($code)->toBe(9)->and($json['errors'][0]['code'])->toBe('operation_unfinished');
});

it('refuses an empty answer to a prompt with exit 2', function (): void {
    $this->fixture->facts(['tty' => true]);

    $this->artisan('fsa:install', ['--dir' => $this->fixture->directory->root, '--owner-email' => 'owner@example.com'])
        ->expectsQuestion('Organization name', '')
        ->assertExitCode(2);
    expect($this->fixture->docker->calls)->toBe([]);
});

it('doubles the memory limits and warns when DOCKER_DEFAULT_PLATFORM runs another architecture (ADR 0070)', function (?string $platform, string $mailpit): void {
    $this->fixture->docker->info = new DockerInfo('29.9.0', 'Docker Desktop', 'aarch64', 8 * 1024 ** 3, 4, false, '2026-10-18T10:00:00Z');
    $this->fixture->facts(['platform' => $platform]);

    [$code, $json] = installOnce($this->fixture);
    $services = ComposeFile::document($this->fixture->directory)['services'];

    expect($code)->toBe(0, json_encode($json))
        ->and($services['mailpit']['mem_limit'])->toBe($mailpit)
        ->and(collect($json['warnings'])->pluck('code')->contains('doctor_architecture'))->toBe($platform === 'linux/amd64');
})->with([
    'native' => [null, '48m'],
    'the engine\'s own platform selected' => ['linux/arm64', '48m'],
    'amd64 emulated on arm64' => ['linux/amd64', '96m'],
]);
