<?php

declare(strict_types=1);

use App\Domain\Instance\Adopt\AdoptSettings;
use App\Domain\Instance\Adopt\DataSnapshot;
use App\Domain\Instance\Adopt\LegacyCheckout;
use App\Domain\Instance\Adopt\LegacyContainers;
use App\Domain\Instance\Adopt\LegacyInstall;
use App\Domain\Instance\Adopt\LegacyStack;
use App\Domain\Instance\Adopt\QueueDrain;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Doctor\PortProbe;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\InvalidInput;
use Illuminate\Support\Sleep;
use Tests\Support\Instance\AdoptFixture;
use Tests\Support\Instance\RecordingDockerGateway;

/*
 * The parts of `fsa adopt` (plan U19, ADR 0087) on their own: snapshots and their comparison, the mode and settings taken
 * from a 1.0 `.env`, reading a checkout, and starting 1.0 again.
 */

beforeEach(function (): void {
    $this->checkout = sys_get_temp_dir().'/fsa-test-parts-'.bin2hex(random_bytes(4));
    mkdir($this->checkout, 0700, true);
    file_put_contents($this->checkout.'/compose.yaml', AdoptFixture::compose());
    file_put_contents($this->checkout.'/package.json', '{"version":"1.2.3"}');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->checkout));
});

function adoptCheckout(string $path, string $env): LegacyCheckout
{
    file_put_contents($path.'/.env', $env);

    return LegacyCheckout::read($path);
}

it('compares snapshots: counts, chain heads and the migration set, and counts the writes since a baseline', function (): void {
    $base = DataSnapshot::fromJson('{"postgres":"16.4","migrations":3,"last_migration":"c","counts":{"users":2,"submissions":5},"heads":{"0":{"seq":4,"hash":"aa"},"7":{"seq":9,"hash":"bb"}}}');
    $later = DataSnapshot::fromArray([...$base->toArray(), 'migrations' => 4, 'last_migration' => 'd', 'counts' => ['users' => 2, 'submissions' => 8, 'forms' => 1], 'heads' => ['0' => ['seq' => 6, 'hash' => 'cc'], '7' => ['seq' => 9, 'hash' => 'bb'], '8' => ['seq' => 2, 'hash' => 'dd']]]);

    expect($base->differences($base))->toBe([])
        ->and($later->differences($base))->toBe([
            'submissions: 8 rows instead of 5', 'forms: 1 rows instead of none', 'audit chain 0: head 6/cc instead of 4/aa', 'audit chain 8: head 2/dd instead of none',
            'migrations: 4 (last d) instead of 3 (last c)',
        ])
        ->and($base->differences($later))->toContain('forms: missing rows instead of 1')
        ->and($later->writesSince($base))->toBe(['audit_entries' => 4, 'rows' => ['forms' => 1, 'submissions' => 3], 'any' => true])
        ->and($base->writesSince($base))->toBe(['audit_entries' => 0, 'rows' => [], 'any' => false])
        ->and(fn () => DataSnapshot::fromJson('not json'))->toThrow(InstanceException::class);
});

it('infers proxy mode only for a URL on another host than the loopback', function (?string $url, bool $proxy): void {
    expect(AdoptSettings::behindProxy($url))->toBe($proxy);
})->with([
    'localhost' => ['http://localhost:8080', false],
    'loopback v4' => ['http://127.0.0.1:8080', false],
    'loopback v6' => ['http://[::1]:8080', false],
    '*.localhost' => ['http://forms.localhost', false],
    'none' => [null, false],
    'public https' => ['https://forms.example.com', true],
    'LAN http' => ['http://10.0.0.5:8080', true],
]);

it('reads a checkout: the project name, keys, port and settings, and only the allow-listed values', function (): void {
    $checkout = adoptCheckout($this->checkout, AdoptFixture::env(['COMPOSE_PROJECT_NAME' => 'forms-prod', 'APP_PORT' => 'eighty', 'APP_PREVIOUS_KEYS' => 'base64:'.base64_encode(str_repeat('p', 32)).',not-a-key',
        'MAIL_FROM_NAME' => '"${APP_NAME}"', 'MAIL_HOST' => 'mailpit']));

    expect($checkout->project)->toBe('forms-prod')->and($checkout->version)->toBe('1.2.3')
        ->and($checkout->port())->toBe(8080)
        ->and($checkout->previousKeys())->toBe(['base64:'.base64_encode(str_repeat('p', 32))])
        ->and($checkout->allowed())->not->toHaveKey('DB_PASSWORD')->not->toHaveKey('COMPOSE_FILE')->toHaveKey('APP_KEY')
        ->and($checkout->value('DB_PASSWORD'))->toBeNull()->and($checkout->value('MAIL_PASSWORD'))->toBeNull()
        ->and(AdoptSettings::overlay($checkout))->toBe(['db_database' => 'form_schema', 'db_username' => 'form_schema', 'mail_from_address' => 'forms@legacy.test'])
        ->and(AdoptSettings::pendingMail($checkout))->toBeNull()
        ->and(print_r($checkout, true))->not->toContain(AdoptFixture::APP_KEY);
});

it('takes the project name from compose.yaml, else the directory', function (): void {
    expect(adoptCheckout($this->checkout, AdoptFixture::env())->project)->toBe('form-schema-app');
    file_put_contents($this->checkout.'/compose.yaml', str_replace('name: form-schema-app', '', AdoptFixture::compose()));
    expect(adoptCheckout($this->checkout, AdoptFixture::env())->project)->toBe(basename($this->checkout));
});

it('refuses a URL an instance cannot serve with exit 7', function (): void {
    $checkout = adoptCheckout($this->checkout, AdoptFixture::env(['APP_URL' => 'https://forms.example.com/sub/path']));

    expect(fn () => AdoptSettings::request($checkout, null, true))->toThrow(fn (InstanceException $e) => expect($e->errorCode)->toBe('unsupported_url')->and($e->exitCode->value)->toBe(7));
});

it('refuses a checkout it cannot read with exit 2', function (): void {
    expect(fn () => LegacyCheckout::read('relative/path'))->toThrow(InvalidInput::class);
    file_put_contents($this->checkout.'/.env', AdoptFixture::env());
    chmod($this->checkout.'/.env', 0000);
    expect(fn () => LegacyCheckout::read($this->checkout))->toThrow(fn (InvalidInput $e) => expect($e->errorCode)->toBe('checkout_unreadable'));
    chmod($this->checkout.'/.env', 0600);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root reads every file');

/** A stopped 1.0 container labelled the way Compose labels one started in `$checkout`. */
function legacyContainer(string $id, string $service, string $checkout, string $state = 'exited', ?string $health = null): ContainerInfo
{
    return new ContainerInfo($id, 'form-schema-app-'.$service.'-1', $state, '2026-10-01T10:00:00Z', $health, 'image', [
        Labels::COMPOSE_PROJECT => 'form-schema-app', Labels::COMPOSE_SERVICE => $service, LegacyInstall::ONE_OFF => 'False',
        LegacyInstall::WORKING_DIR => $checkout, LegacyInstall::CONFIG_FILES => $checkout.'/compose.yaml',
    ]);
}

it('starts 1.0 again Postgres first, waits until it is healthy, then the app roles', function (): void {
    Sleep::fake();
    $docker = new RecordingDockerGateway;
    foreach (['a1' => 'app', 'q1' => 'queue', 'p1' => 'postgres', 'r1' => 'redis'] as $id => $service) {
        $docker->containers[$id] = legacyContainer($id, $service, $this->checkout);
    }
    $legacy = new LegacyInstall($this->checkout, 'form-schema-app', '1.0.0', ['app' => 'a1', 'queue' => 'q1', 'postgres' => 'p1', 'redis' => 'r1'], ['app', 'queue', 'postgres', 'redis'],
        ['pg-data' => 'form-schema-app_pg-data'], 'form_schema', 'form_schema', 8080, null);
    $health = ['starting', 'starting', 'healthy'];
    $docker->onCall['inspectContainer'] = function (array $args) use ($docker, &$health): void {
        if ($args['id'] === 'p1' && $docker->called('startContainers') !== []) {
            $docker->containers['p1'] = legacyContainer('p1', 'postgres', $this->checkout, 'running', array_shift($health) ?? 'healthy');
        }
    };
    $stack = new LegacyStack($docker, new QueueDrain($docker), new PortProbe($docker));

    $started = $stack->restart($legacy);

    expect($started)->toBe(['postgres', 'redis', 'app', 'queue'])
        ->and(array_column($docker->called('startContainers'), 'ids'))->toBe([['p1', 'r1'], ['a1', 'q1']])
        ->and(array_filter(array_column($docker->called('inspectContainer'), 'id'), static fn (string $id): bool => $id === 'p1'))->not->toBeEmpty()
        ->and(LegacyInstall::fromArray($legacy->toArray()))->toEqual($legacy);
    Sleep::assertSleptTimes(2);
});

it('starts the container a service has now, not the one adopt found, and fails on one that is gone (review S3)', function (): void {
    Sleep::fake();
    $docker = new RecordingDockerGateway;
    foreach (['a2' => 'app', 'p1' => 'postgres'] as $id => $service) {
        $docker->containers[$id] = legacyContainer($id, $service, $this->checkout);
    }
    $legacy = new LegacyInstall($this->checkout, 'form-schema-app', '1.0.0', ['app' => 'a1', 'postgres' => 'p1', 'queue' => 'q1'], ['app', 'postgres', 'queue'],
        ['pg-data' => 'form-schema-app_pg-data'], 'form_schema', 'form_schema', 8080, null);
    $stack = new LegacyStack($docker, new QueueDrain($docker), new PortProbe($docker));

    expect(fn () => $stack->restart($legacy))->toThrow(fn (DockerCommandFailed $e) => expect($e->outcome->stderr)->toContain('No such container: q1'))
        ->and(array_column($docker->called('startContainers'), 'ids'))->toBe([['p1'], ['a2', 'q1']])
        ->and(fn () => $docker->stopContainers(['gone'], 10))->toThrow(fn (DockerCommandFailed $e) => expect($e->outcome->stderr)->toContain('No such container: gone'));
});

it('refuses a 1.0 container without the checkout\'s labels, or a scaled service (review S3)', function (array $containers, string $error): void {
    $docker = new RecordingDockerGateway;
    foreach ($containers as $container) {
        $docker->containers[$container->id] = $container;
    }

    expect(fn () => LegacyContainers::find($docker, 'form-schema-app', $this->checkout))->toThrow(fn (InstanceException $e) => expect($e->errorCode)->toBe($error)->and($e->exitCode->value)->toBe(7));
})->with([
    'no working_dir label' => [fn (): array => [new ContainerInfo('a1', 'x', 'running', null, null, 'image', [Labels::COMPOSE_PROJECT => 'form-schema-app', Labels::COMPOSE_SERVICE => 'app', LegacyInstall::ONE_OFF => 'False'])], 'legacy_unlabelled'],
    'two app containers' => [fn (): array => [legacyContainer('a1', 'app', test()->checkout), legacyContainer('a2', 'app', test()->checkout)], 'legacy_scaled'],
]);

it('reads REDIS_DB from the checkout: 0 when unset, refused when it is no database number (review A5)', function (): void {
    expect(adoptCheckout($this->checkout, AdoptFixture::env())->redisDb())->toBe(0)
        ->and(adoptCheckout($this->checkout, AdoptFixture::env(['REDIS_DB' => '3']))->redisDb())->toBe(3)
        ->and(fn () => adoptCheckout($this->checkout, AdoptFixture::env(['REDIS_DB' => '1; FLUSHALL']))->redisDb())->toThrow(fn (InstanceException $e) => expect($e->errorCode)->toBe('unsupported_redis_db'));
});
