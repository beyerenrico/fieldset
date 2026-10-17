<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\Labels;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\TestImage;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U3 / M9-T04: real `docker compose config` accepts the rendered project (plan U3 verification). Nothing is
 * started: `config` only parses and normalizes, so the journey must leave no container, volume or network.
 * Skips only when Docker is unreachable; FSA_REQUIRE_DOCKER=1 turns that skip into a failure.
 */

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
});

afterEach(fn () => TemporaryInstance::cleanup());

/**
 * @param  array<string, string|int|bool|null>  $settings
 * @return array{0: array<string, mixed>, 1: string}
 */
function composeConfigOf(array $settings): array
{
    $directory = TemporaryInstance::directory();
    $state = ComposeFixtures::state($settings, $directory->root);
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
        ->and(array_keys($config['services']))->toEqualCanonicalizing(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit'])
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
