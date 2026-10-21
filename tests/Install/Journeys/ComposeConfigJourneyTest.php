<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\StackFile;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\State\InstanceState;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\TestImage;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U3 / M9-T04: real `docker compose config` accepts the rendered project (plan U3 verification). Nothing is
 * started: `config` only parses and normalizes, so the journey must leave no container, volume or network.
 * Skips only when Docker is unreachable; FIELDSET_REQUIRE_DOCKER=1 turns that skip into a failure.
 */

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
});

afterEach(fn () => TemporaryInstance::cleanup());

/**
 * @param  array<string, string|int|bool|null>  $settings
 * @return array{0: array<string, mixed>, 1: string}
 */
function composeConfigOf(array $settings, ?InstanceState $given = null): array
{
    $directory = TemporaryInstance::directory();
    $state = $given ?? ComposeFixtures::state($settings, $directory->root);
    $secrets = ComposeFixtures::secrets()->withSmtpCredentials('mailer', ComposeFixtures::SMTP_PASSWORD);
    (new ComposeRenderer)->write($directory, $state, $secrets);
    $project = 'fsa-test-'.bin2hex(random_bytes(4));

    $process = new Process(
        ['docker', 'compose', '--project-name', $project, '--file', $directory->path('compose.yaml'), '--project-directory', $directory->root, 'config', '--format', 'json'],
        $directory->root,
        ['COMPOSE_DISABLE_ENV_FILE' => '1', 'COMPOSE_FILE' => false, 'COMPOSE_PROJECT_NAME' => false, 'COMPOSE_PROFILES' => false],
    );
    $process->setTimeout(120)->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return [json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR), $project];
}

function assertNoDockerResources(): void
{
    $filter = 'label='.Labels::INSTANCE.'='.ComposeFixtures::UUID;
    foreach ([['ps', '-a'], ['volume', 'ls'], ['network', 'ls']] as $list) {
        $process = new Process(['docker', ...$list, '--quiet', '--filter', $filter]);
        $process->setTimeout(60)->mustRun();
        expect(trim($process->getOutput()))->toBe('', implode(' ', $list).' found resources');
    }
}

it('is accepted by docker compose config in capture mode, with labels, limits and loopback ports', function (): void {
    [$config, $project] = composeConfigOf(['port' => 18080, 'mail_ui_port' => 18025]);

    expect($config['name'])->toBe($project)
        ->and(array_keys($config['services']))->toEqualCanonicalizing(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup'])
        ->and($config['services']['app']['environment']['APP_KEY'])->toBe(ComposeFixtures::APP_KEY)
        ->and($config['services']['gotenberg']['environment'])->not->toHaveKey('APP_KEY')
        ->and($config['services']['app']['ports'][0])->toMatchArray(['target' => 8080, 'published' => '18080', 'host_ip' => '127.0.0.1'])
        ->and($config['services']['mailpit']['ports'])->toHaveCount(1)
        ->and($config['networks']['renderer']['internal'])->toBeTrue();
    foreach ($config['services'] as $name => $service) {
        expect($service['labels'][Labels::INSTANCE])->toBe(ComposeFixtures::UUID, $name)
            ->and($service['mem_limit'])->not->toBeEmpty()
            ->and($service['restart'])->toBe('unless-stopped')
            ->and($service['environment'] ?? [])->not->toHaveKey('RUN_MIGRATIONS');
    }
    foreach ([...array_values($config['volumes']), ...array_values($config['networks'])] as $resource) {
        expect($resource['labels'][Labels::INSTANCE])->toBe(ComposeFixtures::UUID);
    }
    assertNoDockerResources();
});

it('is accepted by docker compose config with SMTP and the largest size', function (): void {
    [$config] = composeConfigOf([...ComposeFixtures::smtpSettings(), 'size' => 'large']);

    expect($config['services'])->not->toHaveKey('mailpit')
        // `config` prints `$` as `$$` so its output stays a valid compose file; containers get the literal value.
        ->and(str_replace('$$', '$', $config['services']['queue']['environment']['MAIL_PASSWORD']))->toBe(ComposeFixtures::SMTP_PASSWORD)
        ->and($config['services']['postgres']['shm_size'])->not->toBeEmpty();
    assertNoDockerResources();
});

it('is accepted by docker compose config in proxy mode, with only the app on the external network (U9)', function (): void {
    [$config] = composeConfigOf([], ComposeFixtures::proxyState('traefik'));

    expect($config['networks']['proxy'])->toMatchArray(['name' => 'edge-net', 'external' => true])
        ->and($config['networks']['proxy'])->not->toHaveKey('labels')
        ->and(array_keys($config['services']['app']['networks']))->toEqualCanonicalizing(['default', 'renderer', 'proxy'])
        ->and($config['services']['app'])->not->toHaveKey('ports')
        ->and($config['services']['app']['labels']['traefik.enable'])->toBe('true')
        ->and($config['services']['app']['environment']['TRUSTED_PROXIES'])->toBe(ComposeFixtures::PROXY_ADDRESS)
        ->and($config['services']['mailpit']['ports'][0]['host_ip'])->toBe('127.0.0.1');
    foreach (['queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup'] as $service) {
        expect(array_keys($config['services'][$service]['networks']))->not->toContain('proxy');
    }
    assertNoDockerResources();
});

/** @return array{0: Process, 1: string} `docker compose config` of the stack file with only these variables */
function stackConfig(array $variables): array
{
    $directory = sys_get_temp_dir().'/fsa-test-stack-'.bin2hex(random_bytes(4));
    mkdir($directory);
    StackFile::render(ComposeFixtures::digests(), '2.0.0')->writeTo($directory.'/'.StackFile::NAME);
    $unset = array_fill_keys(['COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'COMPOSE_PROFILES', 'APP_URL', 'APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'MAILPIT_UI_PASSWORD', 'FIELDSET_REGISTRY', 'APP_PORT', 'MAIL_HOST'], false);
    $process = new Process(['docker', 'compose', '--project-name', 'fsa-test-stack', '--file', $directory.'/'.StackFile::NAME, 'config', '--format', 'json'], $directory, [...$unset, ...$variables]);
    $process->setTimeout(120)->run();
    exec('rm -rf '.escapeshellarg($directory));

    return [$process, $directory];
}

it('parses the stack file with Compose from environment variables alone and requires the secrets (R18)', function (): void {
    [$process] = stackConfig(['APP_URL' => 'https://forms.example.com', 'APP_KEY' => 'base64:a2V5', 'DB_PASSWORD' => 'db1', 'REDIS_PASSWORD' => 'rd1', 'MAILPIT_UI_PASSWORD' => 'mp1', 'APP_PORT' => '18090', 'MAIL_HOST' => 'smtp.example.com']);
    [$missing] = stackConfig(['APP_URL' => 'https://forms.example.com']);
    $config = json_decode($process->getOutput(), true);
    $digests = ComposeFixtures::digests();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_keys($config['services']))->toEqualCanonicalizing(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit'])
        ->and($config['services']['app']['image'])->toBe($digests['app'])
        ->and($config['services']['postgres']['image'])->toBe($digests['postgres'])
        ->and($config['services']['app']['environment'])->toMatchArray(['APP_URL' => 'https://forms.example.com', 'APP_KEY' => 'base64:a2V5', 'DB_PASSWORD' => 'db1', 'MAIL_HOST' => 'smtp.example.com', 'MAIL_PORT' => '1025', 'TRUSTED_PROXIES' => '', 'RUN_MIGRATIONS' => 'true'])
        ->and($config['services']['redis']['environment']['VALKEY_EXTRA_FLAGS'])->toBe('--requirepass rd1')
        ->and($config['services']['mailpit']['environment']['MP_UI_AUTH'])->toBe('fsa:mp1')
        ->and($config['services']['app']['ports'][0])->toMatchArray(['published' => '18090', 'host_ip' => '127.0.0.1'])
        ->and($config['services']['queue']['depends_on']['app']['condition'])->toBe('service_healthy')
        ->and($missing->getExitCode())->not->toBe(0)
        ->and($missing->getErrorOutput())->toContain('see the header');
});
