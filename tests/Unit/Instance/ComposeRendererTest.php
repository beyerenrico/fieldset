<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ComposeGuard;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ResourceBudget;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\ServiceSpec;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\DomainFixtures;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U3: the generated compose project for local mode (KTD6, KTD7, KTD11, KTD13, KTD25, E16).
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('names the project fsa-<slug>-<hex> in Compose grammar, even for dotted or capitalised directories', function (string $path, string $slug): void {
    $rendered = ComposeFixtures::render(path: $path);

    expect($rendered->project)->toMatch('/^fsa-'.$slug.'-[0-9a-f]{4}$/')
        ->toMatch(ComposeProject::NAME_PATTERN)
        ->and($rendered->document['name'])->toBe($rendered->project);
})->with([
    'dots and capitals' => ['/srv/My.Forms Instance', 'my-forms-instance'],
    'hidden directory' => ['/home/ana/.Form-Schema.App', 'form-schema-app'],
    'symbols only' => ['/srv/...', 'instance'],
]);

it('labels every service, volume and network with the instance UUID and never uses the com.docker.compose prefix', function (array $settings): void {
    $labels = ComposeFixtures::labelsByResource(ComposeFixtures::render($settings)->document);

    expect($labels)->toHaveKeys(['services.app', 'services.queue', 'services.scheduler', 'services.postgres', 'services.redis', 'services.gotenberg', 'volumes.pg-data', 'volumes.app-storage', 'volumes.valkey-data', 'networks.default', 'networks.renderer']);
    foreach ($labels as $resource => $map) {
        expect($map[Labels::INSTANCE] ?? null)->toBe(ComposeFixtures::UUID, $resource);
        foreach (array_keys($map) as $key) {
            expect($key)->not->toStartWith('com.docker.compose');
        }
    }
})->with([
    'capture mail' => [[]],
    'smtp' => [ComposeFixtures::smtpSettings()],
]);

it('gives every service a memory limit from the budget and restart unless-stopped', function (string $size): void {
    $rendered = ComposeFixtures::render(['size' => $size]);
    $budget = ResourceBudget::forSize($size);

    foreach ($rendered->document['services'] as $name => $service) {
        expect($service['mem_limit'])->toBe($budget->memoryLimit($name), $name)
            ->and($service['restart'])->toBe('unless-stopped', $name);
    }
    expect($rendered->memoryLimits())->toHaveCount(count($rendered->services()))
        ->and(array_sum($rendered->memoryLimits()))->toBeLessThanOrEqual($budget->availableMib());
})->with(ResourceBudget::SIZES);

it('doubles every memory limit when the images run emulated, and changes nothing else (ADR 0070)', function (): void {
    $native = ComposeFixtures::render();
    $emulated = new ComposeRenderer()->render(ComposeFixtures::state(), ComposeFixtures::secrets(), emulated: true);
    $withoutLimits = fn (array $services): array => array_map(fn (array $service): array => array_diff_key($service, ['mem_limit' => true]), $services);

    expect($emulated->memoryLimits())->toBe(array_map(fn (int $mib): int => $mib * RenderContext::EMULATION_MEMORY_FACTOR, $native->memoryLimits()))
        ->and($emulated->document['services']['mailpit']['mem_limit'])->toBe('96m')
        ->and($withoutLimits($emulated->document['services']))->toBe($withoutLimits($native->document['services']))
        ->and($emulated->environments)->toBe($native->environments);
});

it('runs every service from the digest pinned in the state', function (): void {
    $services = ComposeFixtures::render()->document['services'];
    $digests = ComposeFixtures::digests();

    expect($services['app']['image'])->toBe($digests['app'])
        ->and($services['queue']['image'])->toBe($digests['app'])
        ->and($services['scheduler']['image'])->toBe($digests['app'])
        ->and($services['postgres']['image'])->toBe($digests['postgres'])
        ->and($services['redis']['image'])->toBe($digests['valkey'])
        ->and($services['gotenberg']['image'])->toBe($digests['gotenberg'])
        ->and($services['mailpit']['image'])->toBe($digests['mailpit']);
});

it('refuses to render a service whose image has no pinned digest', function (): void {
    $state = ComposeFixtures::state()->withRelease('2.0.0', array_diff_key(ComposeFixtures::digests(), ['gotenberg' => true]));

    expect(fn () => (new ComposeRenderer)->render($state, ComposeFixtures::secrets()))->toThrow(InvalidState::class, 'gotenberg');
});

it('points app, queue and scheduler at Mailpit in capture mode and never publishes its SMTP port', function (): void {
    $rendered = ComposeFixtures::render(['mail_ui_port' => 18025]);

    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($rendered->environments[$role])->toMatchArray(['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'mailpit', 'MAIL_PORT' => '1025'])
            ->not->toHaveKey('MAIL_PASSWORD');
    }
    $mailpit = $rendered->service('mailpit');
    expect($mailpit['ports'])->toBe([['target' => 8025, 'published' => '18025', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']])
        ->and($mailpit['volumes'])->toBe([['type' => 'volume', 'source' => 'mailpit-data', 'target' => '/data']])
        ->and($rendered->document['volumes'])->toHaveKey('mailpit-data')
        ->and($rendered->environments['mailpit'])->toMatchArray([
            'MP_UI_AUTH' => 'fsa:'.ComposeFixtures::CATCHER_PASSWORD,
            'MP_MAX_MESSAGES' => '500',
            'MP_DATABASE' => '/data/mailpit.db',
            'MP_DISABLE_VERSION_CHECK' => 'true',
        ]);
    foreach ($rendered->document['services'] as $name => $service) {
        foreach ($service['ports'] ?? [] as $port) {
            expect($port['target'])->not->toBe(1025, $name)->and($port['host_ip'])->toBe('127.0.0.1', $name);
        }
    }
});

it('renders no Mailpit when SMTP is configured and gives the SMTP credentials only to the app roles', function (): void {
    $secrets = ComposeFixtures::secrets()->withSmtpCredentials('mailer', ComposeFixtures::SMTP_PASSWORD);
    $rendered = (new ComposeRenderer)->render(ComposeFixtures::state([...ComposeFixtures::smtpSettings(), 'smtp_scheme' => 'smtps']), $secrets);

    expect($rendered->services())->not->toContain('mailpit')
        ->and($rendered->document['volumes'])->not->toHaveKey('mailpit-data')
        ->and($rendered->environments)->not->toHaveKey('mailpit');
    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($rendered->environments[$role])->toMatchArray([
            'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.com', 'MAIL_PORT' => '587', 'MAIL_SCHEME' => 'smtps',
            'MAIL_USERNAME' => 'mailer', 'MAIL_PASSWORD' => ComposeFixtures::SMTP_PASSWORD, 'MAIL_FROM_ADDRESS' => 'forms@example.com',
        ]);
    }
    foreach (['postgres', 'redis', 'gotenberg'] as $service) {
        expect($rendered->environments[$service])->not->toContain(ComposeFixtures::SMTP_PASSWORD);
    }
});

it('gives APP_KEY and the database credentials only to app, queue and scheduler, whose definition the one-offs run', function (): void {
    $rendered = ComposeFixtures::render();

    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($rendered->environments[$role])->toMatchArray([
            'APP_KEY' => ComposeFixtures::APP_KEY, 'DB_HOST' => 'postgres', 'DB_DATABASE' => 'form_schema',
            'DB_USERNAME' => 'form_schema', 'DB_PASSWORD' => ComposeFixtures::DB_PASSWORD, 'REDIS_PASSWORD' => ComposeFixtures::VALKEY_PASSWORD,
        ]);
    }
    expect(ComposeRenderer::ONE_OFF_SERVICE)->toBe('app')
        ->and($rendered->environments['postgres'])->toBe(['POSTGRES_DB' => 'form_schema', 'POSTGRES_USER' => 'form_schema', 'POSTGRES_PASSWORD' => ComposeFixtures::DB_PASSWORD]);
    foreach (['redis', 'gotenberg', 'mailpit'] as $service) {
        $env = $rendered->environments[$service];
        expect($env)->not->toHaveKeys(['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'POSTGRES_PASSWORD'])
            ->and(implode("\n", $env))->not->toContain(ComposeFixtures::APP_KEY)->not->toContain(ComposeFixtures::DB_PASSWORD);
    }
    expect($rendered->environments['redis'])->toBe([
        'VALKEY_EXTRA_FLAGS' => '--requirepass '.ComposeFixtures::VALKEY_PASSWORD,
        'REDISCLI_AUTH' => ComposeFixtures::VALKEY_PASSWORD,
        'VALKEYCLI_AUTH' => ComposeFixtures::VALKEY_PASSWORD,
    ]);
    foreach (ComposeFixtures::secretValues() as $secret) {
        expect($rendered->yaml())->not->toContain($secret);
    }
});

it('gives the backup service the read-only dump role, a key derived from APP_KEY, its fingerprint and the age recipients, never APP_KEY', function (): void {
    $recipient = 'age1'.str_repeat('q', 58);
    $state = ComposeFixtures::state()->withRecipients([$recipient]);
    $env = new ServiceEnvironments(new RenderContext($state, ComposeFixtures::secrets()))->backup();
    $other = new ServiceEnvironments(new RenderContext($state, ComposeFixtures::secrets()->withAppKey('base64:'.base64_encode(str_repeat('x', 32)))))->backup();

    expect($env)->toMatchArray([
        'DB_HOST' => 'postgres', 'DB_DATABASE' => 'form_schema', 'DB_USERNAME' => 'fsa_backup',
        'DB_PASSWORD' => ComposeFixtures::BACKUP_DB_PASSWORD, 'BACKUP_AGE_RECIPIENTS' => $recipient,
    ])
        ->not->toHaveKey('APP_KEY')
        ->and(implode("\n", $env))->not->toContain(ComposeFixtures::APP_KEY)->not->toContain(ComposeFixtures::DB_PASSWORD)
        ->and($env['BACKUP_HMAC_KEY'])->toMatch('/^base64:[A-Za-z0-9+\/]{43}=$/')
        ->and($env['BACKUP_HMAC_KEY'])->not->toBe($other['BACKUP_HMAC_KEY'])
        ->and($env['BACKUP_APP_FINGERPRINTS'])->toMatch('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/')
        ->and($env['BACKUP_APP_FINGERPRINTS'])->not->toBe($other['BACKUP_APP_FINGERPRINTS'])
        ->and($env)->toMatchArray(['BACKUP_SCHEDULE' => '03:30', 'BACKUP_TIME_ZONE' => 'UTC', 'BACKUP_RETENTION' => '7', 'FSA_INSTANCE_ROOT' => '/fsa/instance'])
        ->and(ComposeFixtures::render()->services())->toContain('backup'); // rendered since U16 (ADR 0084)
});

it('mounts the Docker socket into no rendered service', function (): void {
    $rendered = ComposeFixtures::render();

    expect($rendered->yaml())->not->toContain('docker.sock');
    foreach ($rendered->document['services'] as $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            expect($volume['source'])->not->toContain('docker');
        }
    }
});

it('never sets RUN_MIGRATIONS: migrations run only in supervised one-offs', function (): void {
    $rendered = ComposeFixtures::render();

    foreach ($rendered->environments as $service => $env) {
        expect($env)->not->toHaveKey('RUN_MIGRATIONS', $service);
    }
    expect($rendered->yaml())->not->toContain('RUN_MIGRATIONS');
});

it('refuses a service definition that breaks a rendering rule', function (ServiceSpec $spec, string $problem): void {
    expect(fn () => ComposeFixtures::rendererWith($spec)->render(ComposeFixtures::state(), ComposeFixtures::secrets()))
        ->toThrow(InstanceException::class, $problem);
})->with([
    'socket bind' => [new ServiceSpec('edge', ['image' => 'caddy', 'volumes' => [['type' => 'bind', 'source' => '/var/run/docker.sock', 'target' => '/var/run/docker.sock']]]), 'Docker socket'],
    'socket short syntax' => [new ServiceSpec('edge', ['image' => 'caddy', 'volumes' => ['/run/docker.sock:/docker.sock']]), 'Docker socket'],
    // `tunnel`: a budgeted name local mode does not render (the backup service is rendered since U16, ADR 0084)
    'migrations in env file' => [new ServiceSpec('tunnel', ['image' => 'x'], ['RUN_MIGRATIONS' => 'true']), 'RUN_MIGRATIONS'],
    'migrations inline' => [new ServiceSpec('tunnel', ['image' => 'x', 'environment' => ['RUN_MIGRATIONS' => 'true']]), 'RUN_MIGRATIONS'],
    'reserved label' => [new ServiceSpec('edge', ['image' => 'x', 'labels' => ['com.docker.compose.project' => 'other']]), 'com.docker.compose'],
    'line break' => [new ServiceSpec('edge', ['image' => 'x', 'command' => ["caddy\nservices:"]]), 'unsafe value'],
    'interpolation' => [new ServiceSpec('edge', ['image' => 'x', 'command' => ['${APP_KEY}']]), 'unsafe value'],
    'bare dollar' => [new ServiceSpec('edge', ['image' => 'x', 'command' => ['$HOME']]), 'unsafe value'],
    'env value with line break' => [new ServiceSpec('tunnel', ['image' => 'x'], ['TOKEN' => "a\nb"]), 'line break'],
    'no budget' => [new ServiceSpec('extra', ['image' => 'x']), 'memory budget'],
    'duplicate service' => [new ServiceSpec('app', ['image' => 'x']), 'twice'],
]);

it('refuses what would reach past the containers: host binds outside the instance, capabilities, devices, host namespaces (review U18 S2)', function (ServiceSpec $spec, string $problem): void {
    expect(fn () => ComposeFixtures::rendererWith($spec)->render(ComposeFixtures::state(), ComposeFixtures::secrets()))
        ->toThrow(InstanceException::class, $problem);
})->with([
    'absolute bind' => [new ServiceSpec('edge', ['image' => 'x', 'volumes' => [['type' => 'bind', 'source' => '/etc', 'target' => '/host-etc']]]), 'outside the instance'],
    'absolute short syntax' => [new ServiceSpec('edge', ['image' => 'x', 'volumes' => ['/:/host']]), 'outside the instance'],
    'parent directory' => [new ServiceSpec('edge', ['image' => 'x', 'volumes' => [['type' => 'bind', 'source' => './../other', 'target' => '/x']]]), 'outside the instance'],
    'home directory' => [new ServiceSpec('edge', ['image' => 'x', 'volumes' => ['~/.ssh:/x']]), 'outside the instance'],
    'volume backed by a host path' => [new ServiceSpec('edge', ['image' => 'x'], [], ['hostdata' => ['driver_opts' => ['type' => 'none', 'o' => 'bind', 'device' => '/srv']]]), 'driver options'],
    'capability' => [new ServiceSpec('edge', ['image' => 'x', 'cap_add' => ['SYS_ADMIN']]), 'capability'],
    'every capability' => [new ServiceSpec('edge', ['image' => 'x', 'cap_add' => ['ALL']]), 'capability'],
    'device' => [new ServiceSpec('edge', ['image' => 'x', 'devices' => ['/dev/sda:/dev/sda']]), 'devices'],
    'unconfined seccomp' => [new ServiceSpec('edge', ['image' => 'x', 'security_opt' => ['seccomp=unconfined']]), 'security option'],
    'host pid' => [new ServiceSpec('edge', ['image' => 'x', 'pid' => 'host']), 'pid'],
    'host network' => [new ServiceSpec('edge', ['image' => 'x', 'network_mode' => 'host']), 'network_mode'],
    'host ipc' => [new ServiceSpec('edge', ['image' => 'x', 'ipc' => 'host']), 'ipc'],
    'host user namespace' => [new ServiceSpec('edge', ['image' => 'x', 'userns_mode' => 'host']), 'userns_mode'],
    'privileged' => [new ServiceSpec('edge', ['image' => 'x', 'privileged' => true]), 'privileged'],
    'build' => [new ServiceSpec('edge', ['image' => 'x', 'build' => ['context' => '/']]), 'build'],
]);

it('renders every mode within the guard: local, proxy, domain, phone tunnel and local CA, the kept catcher (review U18 S2)', function (InstanceState $state): void {
    $rendered = (new ComposeRenderer)->render($state, ComposeFixtures::secrets());

    expect(fn () => ComposeGuard::assertSafe($rendered->document, $rendered->environments))->not->toThrow(InstanceException::class);
})->with([
    'local' => fn (): InstanceState => ComposeFixtures::state(),
    'smtp' => fn (): InstanceState => ComposeFixtures::state(ComposeFixtures::smtpSettings()),
    'catcher kept' => fn (): InstanceState => ComposeFixtures::state([...ComposeFixtures::smtpSettings(), 'mail_catcher' => InstanceSettings::CATCHER_KEPT]),
    'proxy traefik' => fn (): InstanceState => ComposeFixtures::proxyState(),
    'proxy port' => fn (): InstanceState => ComposeFixtures::proxyState('port', 'http://forms.example.com'),
    'domain' => fn (): InstanceState => DomainFixtures::state(),
    'phone tunnel' => fn (): InstanceState => PhoneFixtures::tunnel(),
    'phone local CA' => fn (): InstanceState => PhoneFixtures::localCa(),
]);

it('keeps a restart policy a later service sets itself, as the tunnel will', function (): void {
    $rendered = ComposeFixtures::rendererWith(new ServiceSpec('tunnel', ['image' => 'cloudflared', 'restart' => 'no'], ['TUNNEL_METRICS' => '0.0.0.0:2000']))
        ->render(ComposeFixtures::state(), ComposeFixtures::secrets());

    expect($rendered->service('tunnel')['restart'])->toBe('no')
        ->and($rendered->service('tunnel')['mem_limit'])->toBe(ResourceBudget::forSize('small')->memoryLimit('tunnel'))
        ->and($rendered->service('tunnel')['env_file'])->toBe(['./env/tunnel.env'])
        ->and($rendered->service('app')['restart'])->toBe('unless-stopped');
});

it('mounts the runtime directory read-only into app, queue and scheduler only', function (): void {
    $rendered = ComposeFixtures::render();
    $mount = ['type' => 'bind', 'source' => './runtime', 'target' => '/run/fsa-runtime', 'read_only' => true, 'bind' => ['create_host_path' => false]];

    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($rendered->service($role)['volumes'])->toContain($mount)
            ->and($rendered->environments[$role]['FSA_RUNTIME_PATH'])->toBe('/run/fsa-runtime');
    }
    foreach (['postgres', 'redis', 'gotenberg', 'mailpit'] as $service) {
        expect(json_encode($rendered->service($service)))->not->toContain('runtime');
    }
});

it('lets queue and scheduler finish running jobs on a stop: a grace period above the longest job timeout', function (): void {
    $rendered = ComposeFixtures::render();
    $longest = max(array_column(config('horizon.defaults'), 'timeout'));

    foreach (['queue', 'scheduler'] as $role) {
        expect($rendered->service($role)['stop_grace_period'])->toBe('100s');
    }
    expect(AppRoles::STOP_GRACE_SECONDS)->toBeGreaterThan($longest)
        ->and(AppRoles::STOP_GRACE_SECONDS)->toBeGreaterThan((int) config('reports.exports.timeout'))
        ->and($rendered->service('app'))->not->toHaveKey('stop_grace_period');
});

it('gives each service only its own env file and no inline environment', function (): void {
    $rendered = ComposeFixtures::render();

    expect(array_keys($rendered->environments))->toBe($rendered->services());
    foreach ($rendered->document['services'] as $name => $service) {
        expect($service['env_file'])->toBe(['./env/'.$name.'.env'])
            ->and($service)->not->toHaveKey('environment');
    }
});

it('publishes only the app and the catcher UI, on loopback, at the ports from the state', function (): void {
    $rendered = ComposeFixtures::render(['port' => 8085]);

    expect($rendered->service('app')['ports'])->toBe([['target' => 8080, 'published' => '8085', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']])
        ->and($rendered->environments['app']['APP_URL'])->toBe('http://localhost:8085')
        ->and($rendered->environments['app']['SESSION_SECURE_COOKIE'])->toBe('false');
    foreach (['queue', 'scheduler', 'postgres', 'redis', 'gotenberg'] as $service) {
        expect($rendered->service($service))->not->toHaveKey('ports');
    }
});

it('keeps Gotenberg on the internal renderer network shared only with app and queue', function (): void {
    $rendered = ComposeFixtures::render();

    expect($rendered->service('gotenberg')['networks'])->toBe(['renderer'])
        ->and($rendered->document['networks']['renderer']['internal'])->toBeTrue()
        ->and($rendered->service('app')['networks'])->toBe(['default', 'renderer'])
        ->and($rendered->service('queue')['networks'])->toBe(['default', 'renderer'])
        ->and($rendered->service('scheduler')['networks'])->toBe(['default'])
        ->and($rendered->service('postgres')['networks'])->toBe(['default'])
        ->and($rendered->service('mailpit')['networks'])->toBe(['default'])
        ->and($rendered->service('gotenberg')['command'])->toContain('--chromium-deny-private-ips=true', '--chromium-deny-public-ips=true', '--chromium-disable-javascript=true');
});

it('carries the healthchecks of compose.yaml', function (): void {
    $rendered = ComposeFixtures::render();

    expect($rendered->service('app')['healthcheck']['test'])->toBe(['CMD', 'curl', '-fsS', 'http://localhost:8080/up'])
        ->and($rendered->service('queue')['healthcheck']['test'])->toBe(['CMD', 'php', 'artisan', 'horizon:status'])
        ->and($rendered->service('scheduler')['healthcheck'])->toBe(['disable' => true])
        ->and($rendered->service('postgres')['healthcheck']['test'])->toBe(['CMD', 'pg_isready', '-h', '127.0.0.1', '-U', 'form_schema', '-d', 'form_schema'])
        ->and($rendered->service('redis')['healthcheck']['test'])->toBe(['CMD-SHELL', 'valkey-cli ping | grep -q PONG'])
        ->and($rendered->service('app')['depends_on'])->toBe(['postgres' => ['condition' => 'service_healthy'], 'redis' => ['condition' => 'service_healthy']]);
});

it('sizes Horizon, FrankenPHP, Gotenberg, Postgres and Valkey from the budget', function (string $size): void {
    $rendered = ComposeFixtures::render(['size' => $size]);
    $budget = ResourceBudget::forSize($size);

    expect($rendered->environments['queue'])->toMatchArray(['HORIZON_MAX_PROCESSES' => (string) $budget->horizonProcesses(), 'HORIZON_EXPORTS_PROCESSES' => '1'])
        ->and($rendered->environments['app']['FRANKENPHP_CONFIG'])->toBe('num_threads '.$budget->frankenphpThreads())
        ->and($rendered->environments['gotenberg'])->toBe([
            'CHROMIUM_MAX_CONCURRENCY' => (string) $budget->gotenbergConcurrency(),
            'CHROMIUM_MAX_QUEUE_SIZE' => (string) $budget->gotenbergQueueSize(),
        ])
        ->and($rendered->service('postgres')['shm_size'])->toBe($budget->postgresShmSize())
        ->and($rendered->service('postgres')['command'])->toContain('shared_buffers='.$budget->postgresSettings()['shared_buffers'])
        ->and($rendered->service('redis')['command'])->toBe(['valkey-server', '--maxmemory', $budget->valkeyMaxMemory(), '--maxmemory-policy', 'volatile-lru']);
})->with(ResourceBudget::SIZES);

it('renders every mode an instance state can have (local, domain with U8, proxy with U9)', function (): void {
    expect(ComposeRenderer::MODES)->toEqualCanonicalizing(InstanceState::MODES);
});

it('writes the compose file, 0600 env files and the runtime directory, and drops the env files of removed services', function (): void {
    $directory = TemporaryInstance::directory();
    $state = ComposeFixtures::state(path: $directory->root);
    $renderer = new ComposeRenderer;

    $written = $renderer->write($directory, $state, ComposeFixtures::secrets());
    $env = new EnvFiles($directory);
    $yaml = (string) $directory->read('compose.yaml');

    expect($env->services())->toBe(['app', 'backup', 'gotenberg', 'mailpit', 'postgres', 'queue', 'redis', 'scheduler'])
        ->and(fileperms($env->path('app')) & 0777)->toBe(0600)
        ->and(fileperms($directory->path('compose.yaml')) & 0777)->toBe(0600)
        ->and($env->read('app')['APP_KEY'])->toBe(ComposeFixtures::APP_KEY)
        ->and($env->read('redis')['VALKEY_EXTRA_FLAGS'])->toBe('--requirepass '.ComposeFixtures::VALKEY_PASSWORD)
        ->and($directory->exists('runtime/runtime.json'))->toBeTrue()
        ->and($yaml)->toBe($written->yaml())
        ->and($yaml)->toStartWith('# Generated by fsa')
        ->and(json_decode(substr($yaml, (int) strpos($yaml, '{')), true, 64, JSON_THROW_ON_ERROR))->toBe($written->document);

    $renderer->write($directory, $state->withSettings(ComposeFixtures::smtpSettings()), ComposeFixtures::secrets());

    expect($env->services())->not->toContain('mailpit')
        ->and($directory->read('compose.yaml'))->not->toContain('mailpit');
});

it('caps log files so a small disk does not fill up', function (): void {
    foreach (ComposeFixtures::render()->document['services'] as $name => $service) {
        expect($service['logging'])->toBe(['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']], $name);
    }
});

it('rejects renderer inputs with line breaks, quotes or interpolation when they are written into the state', function (string $key, string|int $value): void {
    expect(fn () => ComposeFixtures::state()->withSetting($key, $value))->toThrow(InvalidInput::class);
})->with([
    'line break in sender name' => ['mail_from_name', "Forms\nservices:"],
    'double quote' => ['mail_from_name', 'Forms "HQ"'],
    'single quote' => ['mail_from_name', "O'Brien Forms"],
    'interpolation' => ['mail_from_name', '${APP_KEY}'],
    'domain with line break' => ['domain', "forms.example.com\nx"],
    'domain as URL' => ['domain', 'https://forms.example.com'],
    'smtp host with quote' => ['smtp_host', 'smtp".example.com'],
    'port out of range' => ['port', 70000],
    'port zero' => ['port', 0],
    'port as string' => ['port', '8080'],
    'wildcard extra host' => ['extra_hosts', 'forms.example.com,*.example.com'],
    'unknown size' => ['size', 'huge'],
    'unknown setting with interpolation' => ['note', 'a${B}'],
]);

it('rejects an instance URL with credentials, a query or unsafe characters when it is written into the state', function (string $url): void {
    expect(fn () => ComposeFixtures::state()->withUrl($url))->toThrow(InvalidInput::class);
})->with([
    'credentials' => ['https://admin:pw@forms.example.com'],
    'query' => ['https://forms.example.com/?a=b'],
    'interpolation' => ['https://forms.example.com/${APP_KEY}'],
    'line break' => ["https://forms.example.com\n"],
    'port range' => ['https://forms.example.com:99999'],
    'scheme' => ['javascript://forms.example.com'],
]);

it('fails validation for a hostile settings.json, whether imported or read back from a state file', function (): void {
    $hostile = json_decode('{"domain":"forms.example.com\nservices:\n  evil: {}","mail_from_name":"${APP_KEY}","port":"8080; touch /tmp/x"}', true);

    expect(fn () => ComposeFixtures::state()->withSettings($hostile))->toThrow(InvalidInput::class)
        ->and(fn () => InstanceState::fromArray([...ComposeFixtures::state()->toArray(), 'settings' => $hostile]))->toThrow(InvalidState::class, 'settings.');
});
