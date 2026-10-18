<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\EdgeSite;
use App\Domain\Instance\Compose\ResourceBudget;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InvalidState;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\DomainFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U8 (KTD10, R16, R66; ADR 0075): domain mode adds the edge on its own network with a fixed address, which is
 * the app's only trusted proxy, and only the edge publishes ports.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('runs the edge from the pinned Caddy digest as non-root with only NET_BIND_SERVICE', function (): void {
    $edge = DomainFixtures::render()->service('edge');

    expect($edge['image'])->toBe(ComposeFixtures::digests()['caddy'])
        ->and($edge['user'])->toBe(EdgeService::USER)
        ->and($edge['user'])->not->toStartWith('0')
        ->and($edge['cap_drop'])->toBe(['ALL'])
        ->and($edge['cap_add'])->toBe(['NET_BIND_SERVICE'])
        ->and($edge['security_opt'])->toBe(['no-new-privileges:true'])
        ->and($edge['read_only'])->toBeTrue()
        ->and($edge['tmpfs'])->toBe(['/tmp', '/config/caddy'])
        ->and($edge['command'])->toBe(['caddy', 'run', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])
        ->and($edge['depends_on'])->toBe(['app' => ['condition' => 'service_healthy']])
        ->and($edge['healthcheck']['test'])->toBe(['CMD', 'wget', '-q', '-O', '/dev/null', 'http://127.0.0.1:8081/healthz'])
        ->and($edge['restart'])->toBe('unless-stopped');
});

it('publishes only 80, 443 and 443/udp, all from the edge; app and catcher publish nothing', function (): void {
    $services = DomainFixtures::render()->document['services'];
    $published = array_filter(array_map(static fn (array $service): array => $service['ports'] ?? [], $services));

    expect(array_keys($published))->toBe(['edge'])
        ->and($published['edge'])->toBe([
            ['target' => 80, 'published' => '80', 'protocol' => 'tcp'],
            ['target' => 443, 'published' => '443', 'protocol' => 'tcp'],
            ['target' => 443, 'published' => '443', 'protocol' => 'udp'],
        ])
        ->and($services)->toHaveKey('mailpit');
});

it('gives the edge a fixed address on its own network, joined only by the app and the catcher', function (): void {
    $rendered = DomainFixtures::render();
    $members = array_keys(array_filter($rendered->document['services'], static function (array $service): bool {
        $networks = $service['networks'] ?? [];

        return array_key_exists('edge', $networks) || in_array('edge', $networks, true);
    }));

    // Dynamic addresses come only from the upper half, so a member started first never takes the edge's address.
    expect($rendered->document['networks']['edge']['ipam'])->toBe(['config' => [['subnet' => DomainFixtures::SUBNET, 'ip_range' => '10.213.4.40/29', 'gateway' => '10.213.4.33']]])
        ->and($rendered->document['networks']['edge']['labels'][Labels::INSTANCE])->toBe(ComposeFixtures::UUID)
        ->and($rendered->service('edge')['networks'])->toBe(['edge' => ['ipv4_address' => DomainFixtures::EDGE_ADDRESS]])
        ->and($members)->toEqualCanonicalizing(['app', 'mailpit', 'edge'])
        ->and($rendered->service('app')['networks'])->toBe(['default', 'renderer', 'edge'])
        ->and($rendered->service('edge')['networks'])->not->toHaveKey('default');
});

it('trusts exactly the edge address as the app\'s proxy, in every app role and only in domain mode', function (): void {
    $rendered = DomainFixtures::render();
    $local = ComposeFixtures::render();

    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($rendered->environments[$role]['TRUSTED_PROXIES'])->toBe(DomainFixtures::EDGE_ADDRESS, $role)
            ->and($rendered->environments[$role]['APP_URL'])->toBe('https://'.DomainFixtures::DOMAIN)
            ->and($rendered->environments[$role]['SESSION_SECURE_COOKIE'])->toBe('true')
            ->and($local->environments[$role])->not->toHaveKey('TRUSTED_PROXIES');
    }
    foreach (['postgres', 'redis', 'gotenberg', 'mailpit', 'edge'] as $service) {
        expect($rendered->environments[$service])->not->toHaveKey('TRUSTED_PROXIES');
    }
});

it('keeps the certificates on a labelled volume with a fixed name', function (): void {
    $rendered = DomainFixtures::render();
    $volume = $rendered->document['volumes'][EdgeService::CERTIFICATE_VOLUME];

    expect($volume['name'])->toBe($rendered->project.'_edge-data')
        ->and($volume['labels'][Labels::INSTANCE])->toBe(ComposeFixtures::UUID)
        ->and($rendered->service('edge')['volumes'])->toBe([
            ['type' => 'volume', 'source' => 'edge-data', 'target' => '/data/caddy'],
            ['type' => 'bind', 'source' => './edge', 'target' => '/etc/caddy', 'read_only' => true, 'bind' => ['create_host_path' => false]],
        ])
        ->and(DomainFixtures::render(['acme_email' => 'ops@example.com'])->document['volumes']['edge-data'])->toBe($volume);
});

it('renders the Caddyfile next to the compose file and labels the edge with its hash, so a change recreates it', function (): void {
    $rendered = DomainFixtures::render();
    $changed = DomainFixtures::render(['acme_email' => 'ops@example.com']);
    $caddyfile = $rendered->files['edge/Caddyfile'];

    expect($caddyfile)->toBe((new CaddyfileRenderer)->render(EdgeSite::fromSettings(InstanceSettings::of(DomainFixtures::state()))))
        ->and($rendered->service('edge')['labels'][EdgeService::CONFIG_LABEL])->toBe(hash('sha256', $caddyfile))
        ->and($changed->service('edge')['labels'][EdgeService::CONFIG_LABEL])->not->toBe(hash('sha256', $caddyfile));
});

it('hands the edge only the catcher credential it injects, and serves the catcher under /_mail/', function (): void {
    $rendered = DomainFixtures::render();

    expect($rendered->environments['edge'])->toBe([
        'FSA_CATCHER_AUTHORIZATION' => 'Basic '.base64_encode('fsa:'.ComposeFixtures::CATCHER_PASSWORD),
    ])
        ->and($rendered->environments['mailpit']['MP_WEBROOT'])->toBe('/_mail/')
        ->and($rendered->environments['mailpit']['MP_UI_AUTH'])->toBe('fsa:'.ComposeFixtures::CATCHER_PASSWORD)
        ->and(ComposeFixtures::render()->environments['mailpit'])->not->toHaveKey('MP_WEBROOT');
});

it('drops the catcher from the edge with SMTP mail', function (): void {
    $rendered = DomainFixtures::render(ComposeFixtures::smtpSettings());

    expect($rendered->services())->not->toContain('mailpit')
        ->and($rendered->environments['edge'])->toBe([])
        ->and($rendered->files['edge/Caddyfile'])->not->toContain('_mail');
});

it('fits the edge into the memory budget of every size', function (string $size): void {
    $rendered = DomainFixtures::render(['size' => $size]);
    $budget = ResourceBudget::forSize($size);

    expect($rendered->service('edge')['mem_limit'])->toBe($budget->memoryLimit('edge'))
        ->and(array_sum($rendered->memoryLimits()))->toBeLessThanOrEqual($budget->availableMib());
})->with(ResourceBudget::SIZES);

it('does not render the edge in local mode', function (): void {
    $rendered = ComposeFixtures::render();

    expect($rendered->services())->not->toContain('edge')
        ->and($rendered->document['networks'])->not->toHaveKey('edge')
        ->and($rendered->files)->toBe([]);
});

it('refuses domain mode without a domain or an edge subnet in the state', function (string $missing): void {
    $state = DomainFixtures::state([$missing => null]);

    expect(fn () => (new ComposeRenderer)->render($state, ComposeFixtures::secrets()))->toThrow(InvalidState::class, $missing);
})->with(['domain', 'edge_subnet']);

it('writes the Caddyfile 0644 in a 0755 folder and the edge env file 0600, with no secret outside env files', function (): void {
    $directory = TemporaryInstance::directory();
    $state = DomainFixtures::state(path: $directory->root);

    $written = (new ComposeRenderer)->write($directory, $state, ComposeFixtures::secrets());
    $env = new EnvFiles($directory);
    $runtime = json_decode((string) $directory->read('runtime/runtime.json'), true);
    $public = $directory->read('compose.yaml').$directory->read('edge/Caddyfile');

    expect($directory->read('edge/Caddyfile'))->toBe($written->files['edge/Caddyfile'])
        ->and(fileperms($directory->path('edge/Caddyfile')) & 0777)->toBe(0644)
        ->and(fileperms($directory->path('edge')) & 0777)->toBe(0755)
        ->and(fileperms($env->path('edge')) & 0777)->toBe(0600)
        ->and($env->read('edge'))->toBe($written->environments['edge'])
        ->and($runtime['instance_url'])->toBe('https://'.DomainFixtures::DOMAIN)
        ->and($runtime['local_url'])->toBeNull();
    foreach (ComposeFixtures::secretValues() as $secret) {
        expect($public)->not->toContain($secret);
    }
    expect($public)->not->toContain(base64_encode('fsa:'.ComposeFixtures::CATCHER_PASSWORD));
});
