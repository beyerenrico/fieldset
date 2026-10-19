<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FsaCommand;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U13 / M11-T02: `fsa demo` (§7, R29, R30, AE2, ADR 0081) against scripted Docker. The app-side seeder runs as a
 * socket-less one-off with `--passwords=default` on a loopback-only instance and `random` beyond loopback; random
 * passwords leave only under `secrets` (or --secrets-file), never in data, the journal, the state or progress; the
 * demo owner's sign-in link follows the delivery policy; `--rotate-passwords` rotates and revokes.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');
    $this->fixture->oneOffs['app:demo-passwords'] = DemoFixtures::rotated();
});

afterEach(fn () => TemporaryInstance::cleanup());

/** The archived journal of the last `fsa demo` run. */
function demoJournal(InstanceCommandFixture $fixture): JournalRecord
{
    $runs = array_values(array_filter(new OperationJournal($fixture->directory)->history(), static fn (JournalRecord $record): bool => $record->operation() === 'demo'));

    return end($runs) ?: throw new RuntimeException('No archived demo journal.');
}

/** Makes the installed instance reachable beyond loopback: proxy mode with a public URL. */
function demoExposed(InstanceCommandFixture $fixture): void
{
    $fixture->directory->saveState($fixture->state()->withMode('proxy')->withUrl('https://forms.example.com')->withSetting('proxy', 'port'));
}

it('seeds a loopback-only instance with the documented password in data and hands out the demo owner link', function (): void {
    [$code, $json] = $this->fixture->run('fsa:demo');
    $seed = $this->fixture->oneOffCalls('app:demo-seed');
    $link = $this->fixture->oneOffCalls('identity:login-link');
    $journal = demoJournal($this->fixture);
    $history = $this->fixture->state()->history();

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($seed)->toHaveCount(1)
        ->and($seed[0]['service'])->toBe('app')
        ->and($seed[0]['command'])->toBe(['php', 'artisan', 'app:demo-seed', '--passwords=default', '--actor-uid='.posix_getuid(), '--json'])
        ->and($json['data']['demo'])->toMatchArray(['organization' => 'Demo Haustechnik GmbH', 'seeded' => true, 'passwords' => 'default', 'default_password' => 'password', 'exposure' => [], 'delivered' => 'data', 'login_url' => DemoFixtures::LOGIN_URL])
        ->and(array_column($json['data']['demo']['users'], 'email'))->toBe(DemoFixtures::EMAILS)
        ->and($json['data']['url'])->toBe('http://localhost:8080')
        ->and($json['data']['mail'])->toBe(['mode' => 'capture', 'catcher_url' => 'http://localhost:8025', 'catcher_login_command' => 'fsa config show --catcher-login'])
        ->and($json['data']['next_commands'])->toContain($this->fixture->directory->root.'/fsa demo --rotate-passwords')
        ->and($link[0]['command'])->toBe(['php', 'artisan', 'identity:login-link', '--actor-uid='.posix_getuid(), '--json', '--', 'owner@demo.test'])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets')
        ->and($json['secrets'])->toBe([['name' => 'sign_in_link', 'value' => InstanceCommandFixture::ADMIN_LINK, 'expires_at' => '2026-10-18T10:15:00Z']])
        ->and(json_encode($json))->not->toContain('[redacted]')
        ->and(end($history))->toMatchArray(['event' => 'demo_seeded', 'passwords' => 'default'])
        ->and($journal->operation())->toBe('demo')
        ->and(array_column($journal->phases(), 'name'))->toBe(['demo'])
        ->and($this->fixture->directory->exists(OperationJournal::CURRENT))->toBeFalse();
});

it('seeds random passwords beyond loopback and keeps them out of data, journal, state and progress', function (): void {
    demoExposed($this->fixture);
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('random');
    $progress = new BufferedOutput;
    app()->instance(FsaCommand::PROGRESS_OUTPUT, $progress);

    [$code, $json] = $this->fixture->run('fsa:demo');
    $secrets = array_column($json['secrets'], 'value', 'name');
    $journal = json_encode(demoJournal($this->fixture)->toArray());
    $outside = json_encode([$json['data'], $json['warnings']]).$journal.$this->fixture->state()->toJson().$progress->fetch();

    expect($code)->toBe(0, json_encode($json))
        ->and($this->fixture->oneOffCalls('app:demo-seed')[0]['command'])->toContain('--passwords=random')
        ->and($json['data']['demo'])->toMatchArray(['passwords' => 'random', 'exposure' => ['proxy_mode'], 'delivered' => 'secrets'])
        ->and($json['data']['demo'])->not->toHaveKey('default_password')
        ->and($secrets['demo_password:worker@demo.test'])->toBe(DemoFixtures::password('worker@demo.test'))
        ->and($secrets)->toHaveCount(10)
        ->and($outside)->not->toContain('pw-seed-')
        ->and($outside)->not->toContain('admin-token');
});

it('counts a tunnel or phone HTTPS on a local instance, and an unreadable runtime file, as beyond loopback', function (string $setup, array $exposure): void {
    $state = $this->fixture->state();
    match ($setup) {
        'tunnel' => $this->fixture->directory->write(RuntimeWriter::FILE, json_encode(new RuntimeWriter($this->fixture->directory)->document($state, tunnel: ['hostname' => 'quiet-river-1234.trycloudflare.com', 'expires_at' => '2026-10-18T14:00:00Z', 'peer' => '10.213.5.2', 'admin' => false]), JSON_THROW_ON_ERROR), 0644),
        'local-ca' => $this->fixture->directory->saveState($state->withSetting('phone_https', 'local-ca')),
        'broken' => $this->fixture->directory->write(RuntimeWriter::FILE, '{not json', 0644),
    };
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('random');

    [, $json] = $this->fixture->run('fsa:demo');

    expect($json['data']['demo']['exposure'])->toBe($exposure)
        ->and($this->fixture->oneOffCalls('app:demo-seed')[0]['command'])->toContain('--passwords=random');
})->with([
    'tunnel' => ['tunnel', ['tunnel']],
    'local CA' => ['local-ca', ['local_ca']],
    'unreadable runtime' => ['broken', ['runtime_unreadable']],
]);

it('changes no demo data when the demo exists on a loopback instance, still tells the documented password, and reports the new link', function (): void {
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::existing(9);

    [$code, $json] = $this->fixture->run('fsa:demo');

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['demo'])->toMatchArray(['seeded' => false, 'passwords' => 'default', 'default_password' => 'password', 'default_password_users' => 9])
        ->and(collect($json['warnings'])->pluck('code')->all())->toBe(['sign_in_link_issued'])
        ->and($json['warnings'][0]['message'])->toContain('owner@demo.test')
        ->and($this->fixture->oneOffCalls('app:demo-passwords'))->toBe([])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets');
});

it('reports nothing changed when the demo exists and no new link could be issued', function (): void {
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::existing(9);
    $this->fixture->oneOffs['identity:login-link'] = InstanceCommandFixture::oneOffJson(['command' => 'identity:login-link', 'status' => 'failed', 'errors' => [['code' => 'rate_limited', 'message' => 'Too many links.', 'hint' => null, 'retryable' => true]]]);

    [$code, $json] = $this->fixture->run('fsa:demo');

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and(collect($json['warnings'])->pluck('code')->all())->toBe(['sign_in_link_unavailable']);
});

it('rotates an existing demo that still has the documented password once the instance is reachable beyond loopback', function (): void {
    demoExposed($this->fixture);
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::existing(9);

    [$code, $json] = $this->fixture->run('fsa:demo');
    $journal = demoJournal($this->fixture);
    $history = $this->fixture->state()->history();

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeTrue()
        ->and($this->fixture->oneOffCalls('app:demo-passwords')[0]['command'])->toBe(['php', 'artisan', 'app:demo-passwords', '--rotate', '--actor-uid='.posix_getuid(), '--json'])
        ->and($json['warnings'][0]['code'])->toBe('demo_passwords_rotated')
        ->and($json['data']['demo'])->toMatchArray(['passwords' => 'random', 'rotated' => true, 'sessions_ended' => 2, 'tokens_revoked' => 1, 'delivered' => 'secrets'])
        ->and($json['data']['demo'])->not->toHaveKey('default_password')
        ->and(array_column($json['secrets'], 'value', 'name')['demo_password:owner@demo.test'])->toBe(DemoFixtures::password('owner@demo.test', 'rotated'))
        ->and(array_column($journal->phases(), 'name'))->toBe(['demo', 'demo_rotate'])
        ->and(end($history))->toMatchArray(['event' => 'demo_passwords_rotated', 'passwords' => 'random']);
});

it('says how to get new passwords when an existing demo already has random ones', function (): void {
    demoExposed($this->fixture);
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::existing(0);

    [$code, $json] = $this->fixture->run('fsa:demo');

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeTrue() // the new demo owner link
        ->and($json['data']['demo'])->toMatchArray(['passwords' => null, 'delivered' => null])
        ->and($json['warnings'][0]['code'])->toBe('demo_passwords_not_shown')
        ->and($json['warnings'][0]['hint'])->toContain('fsa demo --rotate-passwords')
        ->and($this->fixture->oneOffCalls('app:demo-passwords'))->toBe([]);
});

it('rotates every demo password with --rotate-passwords, into --secrets-file when given, without a sign-in link', function (): void {
    $file = $this->fixture->directory->root.'/demo-passwords.json';

    [$code, $json] = $this->fixture->run('fsa:demo', ['--rotate-passwords' => true, '--secrets-file' => $file]);
    $written = json_decode((string) file_get_contents($file), true);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($this->fixture->oneOffCalls('app:demo-seed'))->toBe([])
        ->and($this->fixture->oneOffCalls('identity:login-link'))->toBe([])
        ->and($json['data']['demo'])->toMatchArray(['rotated' => true, 'passwords' => 'random', 'delivered' => 'secrets_file'])
        ->and($json['secrets'])->each->not->toHaveKey('value')
        ->and(json_encode($json))->not->toContain('pw-rotated-')
        ->and(array_column($written['secrets'], 'value', 'name')['demo_password:viewer@demo.test'])->toBe(DemoFixtures::password('viewer@demo.test', 'rotated'))
        ->and(substr(sprintf('%o', fileperms($file)), -3))->toBe('600');
});

it('reports no_demo_users when there is nothing to rotate', function (): void {
    $this->fixture->oneOffs['app:demo-passwords'] = DemoFixtures::noDemoUsers();

    [$code, $json] = $this->fixture->run('fsa:demo', ['--rotate-passwords' => true]);

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['warnings'][0]['code'])->toBe('no_demo_users')
        ->and($json['secrets'])->toBe([])
        ->and(array_column($this->fixture->state()->history(), 'event'))->not->toContain('demo_passwords_rotated');
});

it('hands the demo owner link to the browser with --open on a desktop, never the passwords', function (): void {
    demoExposed($this->fixture);
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('random');
    $this->fixture->facts(['desktop' => true, 'openUrlFile' => '.fsa-open-url-00000000000000000000000000000000']);

    [, $json] = $this->fixture->run('fsa:demo', ['--open' => true]);

    expect($json['data']['sign_in_link']['delivered'])->toBe('browser')
        ->and(array_column($json['secrets'], 'name'))->not->toContain('sign_in_link')
        ->and($json['secrets'])->toHaveCount(9)
        ->and((string) $this->fixture->directory->read('.fsa-open-url-00000000000000000000000000000000'))->toContain('admin-token')
        ->and((string) $this->fixture->directory->read('.fsa-open-url-00000000000000000000000000000000'))->not->toContain('pw-seed-');
});

it('still succeeds with a warning when no sign-in link can be issued', function (): void {
    $this->fixture->oneOffs['identity:login-link'] = InstanceCommandFixture::oneOffJson(['command' => 'identity:login-link', 'status' => 'failed', 'errors' => [['code' => 'rate_limited', 'message' => 'Too many links.', 'hint' => null, 'retryable' => true]]]);

    [$code, $json] = $this->fixture->run('fsa:demo');

    expect($code)->toBe(0)
        ->and($json['data']['sign_in_link'])->toBeNull()
        ->and($json['warnings'][0]['code'])->toBe('sign_in_link_unavailable')
        ->and($json['warnings'][0]['hint'])->toContain('fsa login-link owner@demo.test --secrets-file <file>');
});

it('exits 4 (retryable) when the seeding one-off fails and resumes on the next run', function (): void {
    $this->fixture->oneOffs['app:demo-seed'] = new ProcessOutcome(1, '', 'could not connect to server');

    [$code, $json] = $this->fixture->run('fsa:demo');
    $open = new OperationJournal($this->fixture->directory)->current();
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');
    [$again, $done] = $this->fixture->run('fsa:demo');
    $events = array_column(demoJournal($this->fixture)->events(), 'type');

    expect($code)->toBe(4)
        ->and($json['errors'][0])->toMatchArray(['code' => 'one_off_failed', 'retryable' => true])
        ->and($json['errors'][0]['hint'])->toContain('fsa status')
        ->and($open?->operation())->toBe('demo')
        ->and($again)->toBe(0, json_encode($done))
        ->and($done['changed'])->toBeTrue()
        ->and($events)->toContain('resumed');
});

it('maps an app-side refusal of the seeding to exit 4 demo_failed', function (): void {
    $this->fixture->oneOffs['app:demo-seed'] = InstanceCommandFixture::oneOffJson(['command' => 'app:demo-seed', 'status' => 'failed', 'errors' => [['code' => 'invalid_input', 'message' => 'Bad mode.', 'hint' => null, 'retryable' => false]]]);

    [$code, $json] = $this->fixture->run('fsa:demo');

    expect($code)->toBe(4)->and($json['errors'][0]['code'])->toBe('demo_failed')->and($json['errors'][0]['message'])->toContain('Bad mode.');
});

it('refuses a locked instance (exit 9) before any one-off', function (): void {
    $this->fixture->docker->withRunningContainer(str_repeat('b', 64), '2026-10-18T10:00:00Z');
    app(InstanceLock::class)->acquire(TemporaryInstance::holder('b', '2026-10-18T10:00:00Z'), 'upgrade');

    [$code, $json] = $this->fixture->run('fsa:demo');

    expect($code)->toBe(9)
        ->and($json['errors'][0]['code'])->toBe('instance_locked')
        ->and($this->fixture->oneOffCalls('app:demo-seed'))->toBe([]);
});

it('refuses an instance that is not installed yet (exit 2) before any one-off', function (): void {
    $fresh = new InstanceCommandFixture;
    $fresh->directory->saveState(InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $fresh->directory->root, 1, 1, 1));

    [$code, $json] = $fresh->run('fsa:demo');

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('not_installed')
        ->and($json['errors'][0]['hint'])->toContain('fsa install --demo')
        ->and($fresh->docker->calls)->toBe([]);
});

it('runs the read-only default-password check for U14 as a socket-less one-off', function (): void {
    $this->fixture->oneOffs['app:demo-passwords'] = InstanceCommandFixture::oneOffJson(['command' => 'app:demo-passwords', 'status' => 'ok',
        'data' => ['checked' => 10, 'count' => 1, 'users' => [['email' => 'real@example.com', 'demo' => false]]]]);

    $result = app(AppCommands::class)->demoPasswordCheck(ComposeProject::forInstance($this->fixture->state(), $this->fixture->directory));

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe(['checked' => 10, 'count' => 1, 'users' => [['email' => 'real@example.com', 'demo' => false]]])
        ->and($this->fixture->oneOffCalls('app:demo-passwords')[0]['service'])->toBe('app')
        ->and($this->fixture->oneOffCalls('app:demo-passwords')[0]['command'])->toBe(['php', 'artisan', 'app:demo-passwords', '--check', '--json']);
});
