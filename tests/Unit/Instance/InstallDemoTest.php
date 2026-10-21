<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U13 / M11-T02: `fieldset install --demo` (R29, AE2, KTD25, ADR 0081): after the stack started, the `demo` phase runs the
 * app-side seeder in a socket-less one-off; a loopback-only local install keeps `password` (reported in data), any
 * install reachable beyond loopback gives random passwords listed once with the owner link under `secrets`.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');
    $this->fixture->oneOffs['app:demo-passwords'] = DemoFixtures::rotated();
});

afterEach(fn () => TemporaryInstance::cleanup());

/** Proxy mode's port setup: reachable beyond loopback by definition. */
function installDemoBehindProxy(InstanceCommandFixture $fixture, array $flags = []): array
{
    $fixture->docker->networkResponder = fn (string $name): ?NetworkInfo => str_ends_with($name, '_default')
        ? new NetworkInfo('net1', $name, false, ['172.31.0.0/16'], ['172.31.0.1'])
        : null;

    return $fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--behind-proxy' => 'port', '--url' => 'https://forms.example.com', '--demo' => true, ...$flags]));
}

it('seeds the demo after the stack started on a loopback-only install and keeps the documented password', function (): void {
    [$code, $json] = $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--demo' => true]));
    $journal = new OperationJournal($this->fixture->directory)->history()[0];
    $calls = $this->fixture->docker->calls;
    $lastUp = max(array_keys(array_filter($calls, static fn (array $call): bool => $call['method'] === 'composeUp')));
    $seedAt = array_key_first(array_filter($calls, static fn (array $call): bool => ($call['args']['command'][2] ?? null) === 'app:demo-seed'));
    $seed = $this->fixture->oneOffCalls('app:demo-seed')[0];

    expect($code)->toBe(0, json_encode($json))
        ->and(array_column($journal->phases(), 'name'))->toBe(['doctor', 'secrets', 'state', 'pull', 'up', 'migrate', 'app_install', 'start', 'demo', 'smoke', 'done'])
        ->and($journal->context()['demo'])->toBe(1)
        ->and($seed['command'])->toBe(['php', 'artisan', 'app:demo-seed', '--passwords=default', '--actor-uid='.posix_getuid(), '--json'])
        ->and($seedAt)->toBeGreaterThan($lastUp)
        ->and($json['data']['demo'])->toMatchArray(['seeded' => true, 'passwords' => 'default', 'default_password' => 'password', 'exposure' => [], 'delivered' => 'data'])
        ->and($json['secrets'])->toBe([['name' => 'sign_in_link', 'value' => InstanceCommandFixture::LINK, 'expires_at' => '2026-10-18T11:00:00Z']])
        ->and(array_column($this->fixture->state()->history(), 'event'))->toBe(['demo_seeded', 'installed']);
});

it('gives random passwords, listed once with the owner link, to an install reachable beyond loopback', function (): void {
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('random');
    $file = $this->fixture->directory->root.'/install-secrets.json';

    [$code, $json] = installDemoBehindProxy($this->fixture, ['--secrets-file' => $file]);
    $written = array_column(json_decode((string) file_get_contents($file), true)['secrets'], 'value', 'name');
    $journal = json_encode(new OperationJournal($this->fixture->directory)->history()[0]->toArray());

    expect($code)->toBe(0, json_encode($json))
        ->and($this->fixture->oneOffCalls('app:demo-seed')[0]['command'])->toContain('--passwords=random')
        ->and($json['data']['demo'])->toMatchArray(['passwords' => 'random', 'exposure' => ['proxy_mode'], 'delivered' => 'secrets_file'])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and(array_column($json['secrets'], 'name'))->toBe(['sign_in_link', ...array_map(static fn (string $email): string => 'demo_password:'.$email, DemoFixtures::EMAILS)])
        ->and($written['demo_password:tablet@demo.test'])->toBe(DemoFixtures::password('tablet@demo.test'))
        ->and($written['sign_in_link'])->toBe(InstanceCommandFixture::LINK)
        ->and(json_encode($json).$journal.$this->fixture->state()->toJson())->not->toContain('pw-seed-');
});

it('reissues random passwords on a resume after an attempt that seeded but never showed them', function (): void {
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('random');
    $this->fixture->smokeOutcome = new ProcessOutcome(7, '', 'connection refused');
    [$failed] = installDemoBehindProxy($this->fixture);
    $this->fixture->smokeOutcome = null;
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::existing(0);

    [$code, $json] = $this->fixture->run('fieldset:install');
    $phases = array_column(new OperationJournal($this->fixture->directory)->history()[0]->phases(), 'name');

    expect($failed)->toBe(4)
        ->and($code)->toBe(0, json_encode($json))
        ->and($json['warnings'][0]['code'])->toBe('demo_passwords_reissued')
        ->and($json['data']['demo'])->toMatchArray(['passwords' => 'random', 'rotated' => true, 'delivered' => 'secrets'])
        ->and(array_column($json['secrets'], 'value', 'name')['demo_password:owner@demo.test'])->toBe(DemoFixtures::password('owner@demo.test', 'rotated'))
        ->and($this->fixture->oneOffCalls('app:demo-seed'))->toHaveCount(2)
        ->and($phases)->toContain('demo_rotate');
});

it('accepts --demo added on a resume before the demo ran', function (): void {
    $this->fixture->smokeOutcome = new ProcessOutcome(7, '', 'connection refused');
    [$failed] = $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags());
    $this->fixture->smokeOutcome = null;

    [$code, $json] = $this->fixture->run('fieldset:install', ['--demo' => true]);

    expect($failed)->toBe(4)
        ->and($code)->toBe(0, json_encode($json))
        ->and($json['data']['demo']['seeded'])->toBeTrue();
});

it('changes nothing for a repeated install with --demo once the demo is there, and points a new --demo at fieldset demo', function (): void {
    $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--demo' => true]));
    $calls = count($this->fixture->docker->calls);
    [$same, $sameJson] = $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--demo' => true]));

    $plain = new InstanceCommandFixture;
    $plain->run('fieldset:install', InstanceCommandFixture::installFlags());
    [$added, $addedJson] = $plain->run('fieldset:install', InstanceCommandFixture::installFlags(['--demo' => true]));

    expect($same)->toBe(0)
        ->and($sameJson['changed'])->toBeFalse()
        ->and(count($this->fixture->docker->calls))->toBe($calls)
        ->and($added)->toBe(2)
        ->and($addedJson['errors'][0]['code'])->toBe('use_config_set')
        ->and($addedJson['data']['flags'])->toBe([['flag' => '--demo', 'key' => null]])
        ->and($addedJson['errors'][0]['hint'])->toContain('fieldset demo');
});

it('fails the install with exit 4 when the demo one-off fails, and resumes at the demo phase', function (): void {
    $this->fixture->oneOffs['app:demo-seed'] = new ProcessOutcome(124, '', '', true);
    [$code, $json] = $this->fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--demo' => true]));
    $this->fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');
    [$resumed, $done] = $this->fixture->run('fieldset:install');

    expect($code)->toBe(4)
        ->and($json['errors'][0])->toMatchArray(['code' => 'one_off_timeout', 'retryable' => true])
        ->and($resumed)->toBe(0, json_encode($done))
        ->and($done['data']['demo']['passwords'])->toBe('default')
        ->and($this->fixture->oneOffCalls('app:install'))->toHaveCount(1);
});
