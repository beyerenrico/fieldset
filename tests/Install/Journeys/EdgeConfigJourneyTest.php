<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Release\ReleaseInputs;
use App\Domain\Instance\State\InstanceSettings;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\TestImage;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\DomainFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U8 (ADR 0075): the rendered domain-mode project is accepted by real `docker compose config`, and the rendered
 * Caddyfile by `caddy validate` of the pinned Caddy image, as the edge mounts it (read-only, non-root). Nothing is
 * started but the throwaway validator. Skips only when Docker is unreachable; FSA_REQUIRE_DOCKER=1 fails instead.
 */

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @param  array<string, string|int|bool|null>  $settings */
function renderedEdge(array $settings): string
{
    $directory = TemporaryInstance::directory();
    $root = null;
    if (($settings['acme_ca_root'] ?? null) === true) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'Edge Config Root'], $key), null, $key, 30), $pem);
        $root = AcmeCaRoot::validated($pem);
        AcmeCaRoot::write($directory, $root);
        $settings['acme_ca_root'] = hash('sha256', $root);
    }
    (new ComposeRenderer)->write($directory, DomainFixtures::state($settings, $directory->root), ComposeFixtures::secrets());

    return $directory->root;
}

function caddyImage(): string
{
    return ReleaseInputs::fromFile(TestImage::repository().'/distribution/release.json')->images['caddy']->pullReference();
}

it('is accepted by docker compose config in domain mode, with only the edge publishing ports', function (): void {
    $root = renderedEdge([]);
    $process = new Process(['docker', 'compose', '--project-name', 'fsa-test-'.bin2hex(random_bytes(4)), '--file', $root.'/compose.yaml', '--project-directory', $root, 'config', '--format', 'json'],
        $root, ['COMPOSE_DISABLE_ENV_FILE' => '1', 'COMPOSE_FILE' => false, 'COMPOSE_PROJECT_NAME' => false, 'COMPOSE_PROFILES' => false]);
    $process->setTimeout(120)->run();
    $config = json_decode($process->getOutput(), true) ?? [];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_keys(array_filter($config['services'] ?? [], static fn (array $service): bool => ($service['ports'] ?? []) !== [])))->toBe(['edge'])
        ->and($config['services']['edge']['networks']['edge']['ipv4_address'] ?? null)->toBe(DomainFixtures::EDGE_ADDRESS)
        ->and($config['networks']['edge']['ipam']['config'][0]['subnet'] ?? null)->toBe(DomainFixtures::SUBNET)
        ->and($config['volumes']['edge-data']['name'] ?? null)->toEndWith('_edge-data');
});

it('renders Caddyfiles that the pinned Caddy validates as the edge mounts them', function (array $settings): void {
    $root = renderedEdge($settings);
    $process = new Process(['docker', 'run', '--rm', '--user', '65532:65532', '--read-only', '--tmpfs', '/tmp', '--tmpfs', '/data/caddy', '--tmpfs', '/config/caddy',
        '--env', 'FSA_CATCHER_AUTHORIZATION=Basic eDp5', '--volume', $root.'/edge:/etc/caddy:ro', caddyImage(), 'caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile']);
    $process->setTimeout(300)->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput().$process->getErrorOutput())->toContain('Valid configuration');
})->with([
    'defaults' => [[]],
    'staging, contact and a custom root' => [['acme_ca' => InstanceSettings::ACME_STAGING, 'acme_email' => 'ops@example.com', 'acme_ca_root' => true]],
    'smtp mail, no catcher' => [ComposeFixtures::smtpSettings()],
]);

/**
 * The request headers each reverse_proxy handler of an adapted Caddy config deletes, by upstream.
 *
 * @param  array<array-key, mixed>  $node
 * @return array<string, list<list<string>>>
 */
function adaptedHeaderDeletes(array $node): array
{
    $found = [];
    if (($node['handler'] ?? null) === 'reverse_proxy') {
        foreach ($node['upstreams'] ?? [] as $upstream) {
            $found[(string) ($upstream['dial'] ?? '')][] = array_values($node['headers']['request']['delete'] ?? []);
        }
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $found = array_merge_recursive($found, adaptedHeaderDeletes($child));
        }
    }

    return $found;
}

it('strips X-Forwarded-Port towards the app and the operator\'s cookies towards Mailpit, as Caddy adapts it (ADR 0079)', function (): void {
    $root = renderedEdge([]);
    $process = new Process(['docker', 'run', '--rm', '--user', '65532:65532', '--read-only', '--tmpfs', '/tmp', '--tmpfs', '/data/caddy', '--tmpfs', '/config/caddy',
        '--volume', $root.'/edge:/etc/caddy:ro', caddyImage(), 'caddy', 'adapt', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile']);
    $process->setTimeout(300)->run();
    $deletes = adaptedHeaderDeletes(json_decode($process->getOutput(), true) ?? []);

    // app:8080 twice: the forward_auth subrequest and the site's catch-all proxy.
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($deletes['app:8080'] ?? [])->toHaveCount(2)
        ->and($deletes['app:8080'] ?? [])->each->toContain('X-Forwarded-Port')
        ->and($deletes['mailpit:8025'] ?? [])->toBe([['Cookie', 'X-XSRF-TOKEN']]);
});
