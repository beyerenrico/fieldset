<?php

declare(strict_types=1);

use App\Console\Commands\DemoSeedCommand;
use App\Domain\Identity\Demo\DefaultDemoPassword;
use App\Domain\Identity\Demo\DemoAccounts;
use App\Domain\Instance\Demo\DemoCredentials;
use App\Domain\Instance\Demo\DemoData;
use App\Domain\Instance\Demo\DemoPasswords;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\DomainFixtures;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U13 / M11-T02: the reachability rule for demo passwords (R29, AE2, ADR 0055, ADR 0081) and the contract between the
 * ops side and the app side (names, prefix, owner account) that the one-offs rely on.
 */

afterEach(fn () => TemporaryInstance::cleanup());

/** A runtime.json as RuntimeWriter writes it (built by hand: datasets load before any test directory exists). */
function demoRuntime(?string $tunnelExpiresAt = null): string
{
    $tunnel = $tunnelExpiresAt === null
        ? ['hostname' => null, 'expires_at' => null, 'peer' => null, 'admin' => false]
        : ['hostname' => 'calm-lake-42.trycloudflare.com', 'expires_at' => $tunnelExpiresAt, 'peer' => '10.213.5.2', 'admin' => false];

    return json_encode([
        'schema_version' => RuntimeWriter::SCHEMA_VERSION, 'instance_url' => 'http://localhost:8080', 'local_url' => 'http://localhost:8080', 'extra_hosts' => [],
        'tunnel' => $tunnel, 'gate' => ['raised' => false, 'operation' => null, 'since' => null, 'smoke_token' => null],
    ], JSON_THROW_ON_ERROR);
}

it('keeps the documented password only for a loopback-only local instance', function (InstanceState $state, ?string $runtime, array $exposure, string $mode): void {
    $directory = TemporaryInstance::directory();
    if ($runtime !== null) {
        $directory->ensureDirectory('runtime', 0755);
        $directory->write(RuntimeWriter::FILE, $runtime, 0644);
    }

    expect(DemoPasswords::exposure($state, $directory))->toBe($exposure)
        ->and(DemoPasswords::mode(DemoPasswords::exposure($state, $directory)))->toBe($mode);
})->with([
    'local, no runtime file' => [ComposeFixtures::state(), null, [], 'default'],
    'local, runtime without a tunnel' => [ComposeFixtures::state(), demoRuntime(), [], 'default'],
    'domain mode' => [DomainFixtures::state(), null, ['domain_mode'], 'random'],
    'proxy mode' => [ComposeFixtures::state()->withMode('proxy')->withUrl('https://forms.example.com'), null, ['proxy_mode'], 'random'],
    'local CA' => [ComposeFixtures::state(['phone_https' => 'local-ca']), null, ['local_ca'], 'random'],
    'tunnel setting and active tunnel' => [ComposeFixtures::state(['phone_https' => 'tunnel']), demoRuntime('2099-01-01T00:00:00Z'), ['tunnel'], 'random'],
    'an expired tunnel still counts' => [ComposeFixtures::state(), demoRuntime('2020-01-01T00:00:00Z'), ['tunnel'], 'random'],
    'unknown phone HTTPS value' => [ComposeFixtures::state(['phone_https' => 'carrier-pigeon']), null, ['phone_https'], 'random'],
    'unreadable runtime file' => [ComposeFixtures::state(), '{"schema_version": "1.0"', ['runtime_unreadable'], 'random'],
]);

it('shares the owner account, the default password and the secret prefix with the app side', function (): void {
    expect(DemoCredentials::OWNER)->toBe(DemoAccounts::OWNER)
        ->and(DemoCredentials::DEFAULT_PASSWORD)->toBe(DefaultDemoPassword::PASSWORD)
        ->and(DemoCredentials::SECRET_PREFIX)->toBe(DemoSeedCommand::SECRET_PREFIX)
        ->and(DemoData::SEEDED)->toBe('demo_seeded');
});

it('reads only well-formed demo secrets and tolerates a malformed rotation answer', function (): void {
    $result = OneOffResult::fromOutcome(InstanceCommandFixture::oneOffJson(['status' => 'ok', 'data' => ['users' => ['a@demo.test', 7], 'sessions_ended' => 'two'], 'secrets' => [
        ['name' => 'demo_password:a@demo.test', 'value' => 'pw-1', 'expires_at' => null],
        ['name' => 'demo_password:', 'value' => 'pw-empty', 'expires_at' => null],
        ['name' => 'sign_in_link', 'value' => 'http://x/login-link#t', 'expires_at' => null],
    ]]));
    $rotation = DemoCredentials::fromRotation($result, []);

    expect($result->secretsWithPrefix('demo_password:'))->toBe(['a@demo.test' => 'pw-1'])
        ->and($rotation->data)->toMatchArray(['rotated' => true, 'passwords' => 'random', 'users' => [['email' => 'a@demo.test']], 'sessions_ended' => 0, 'tokens_revoked' => 0])
        ->and($rotation->journal())->toBe(['rotated' => true, 'passwords' => 'random', 'users' => 1])
        ->and(print_r($rotation, true))->not->toContain('pw-1');
});

it('describes an answer without organization or users without failing', function (): void {
    $credentials = DemoCredentials::fromSeed(OneOffResult::fromOutcome(new ProcessOutcome(0, '{"schema_version":1,"status":"ok","data":{"seeded":false,"users":[{"name":"x"}]}}')), ['domain_mode']);

    expect($credentials->data)->toMatchArray(['organization' => null, 'seeded' => false, 'passwords' => null, 'users' => [], 'login_url' => null, 'default_password_users' => 0])
        ->and($credentials->changed())->toBeFalse()
        ->and($credentials->defaultPasswordUsers())->toBe(0);
});
