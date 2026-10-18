<?php

declare(strict_types=1);

use App\Domain\Instance\Config\ConfigRollout;
use App\Domain\Instance\Console\ConfigCommand;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M11 gate (ADR 0080 amendment): the SMTP account survives every way a `config set mail` can end, reaches only the
 * server it was given for, and travels only over TLS unless the operator chose a plain relay.
 */

const SAFETY_PASSWORD = 'relay-secret-5e2b';
const SAFETY_USER = 'relay-user-44';

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    app()->instance(ConfigCommand::STDIN, fn (): string => SAFETY_PASSWORD."\n");
});

afterEach(fn () => TemporaryInstance::cleanup());

/**
 * `config set mail smtp` with an account against smtp.example.com.
 *
 * @return array{0: int, 1: array<string, mixed>}
 */
function safetySmtp(ConfigFixture $config, array $options = []): array
{
    return $config->set('mail', 'smtp', ['--smtp-host' => 'smtp.example.com', '--smtp-username' => SAFETY_USER, '--password-stdin' => true, '--from-address' => 'forms@example.com', ...$options]);
}

function safetyEnv(ConfigFixture $config): array
{
    return new EnvFiles($config->instance->directory)->read('app');
}

it('keeps a password with the byte 0x85 (Å, х, ą in UTF-8) whole when a later change renders the env files again', function (): void {
    $password = 'Åsa-хорошо-ą\\';
    app()->instance(ConfigCommand::STDIN, fn (): string => $password."\n");
    [$first] = safetySmtp($this->config);

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($first)->toBe(0)
        ->and($code)->toBe(0, json_encode($json))
        ->and(safetyEnv($this->config))->toMatchArray(['MAIL_USERNAME' => SAFETY_USER, 'MAIL_PASSWORD' => $password, 'MAIL_FROM_ADDRESS' => 'forms@example.com']);
});

it('keeps the SMTP account when a switch to capture mode fails and reverts', function (string $failure): void {
    safetySmtp($this->config, ['--remove-catcher' => true]);
    $ups = 0;
    match ($failure) {
        'catcher not healthy' => $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
            if (++$ups === 1) {
                throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'container mailpit is unhealthy'));
            }
        },
        'smoke test' => $this->config->smokeFails = function (array $urls) use (&$ups): bool {
            return in_array('http://app:8080/up', $urls, true) && ++$ups === 1;
        },
    };

    [$code, $json] = $this->config->set('mail', 'capture');

    expect($code)->toBe(5, json_encode($json))
        ->and($json['data']['reverted'])->toBeTrue()
        ->and($this->config->state()->setting('smtp_host'))->toBe('smtp.example.com')
        ->and(safetyEnv($this->config))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_USERNAME' => SAFETY_USER, 'MAIL_PASSWORD' => SAFETY_PASSWORD])
        ->and($this->config->instance->directory->exists(ConfigRollout::CREDENTIALS_STASH))->toBeFalse();
})->with(['catcher not healthy', 'smoke test']);

it('restores the SMTP account when a later run finishes the revert of a failed switch to capture mode (exit 8, then the same command)', function (): void {
    safetySmtp($this->config, ['--remove-catcher' => true]);
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups <= 2) { // the apply's `up`, then the revert's
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'port is already allocated'));
        }
    };

    [$failed, $first] = $this->config->set('mail', 'capture');
    [$code, $json] = $this->config->set('mail', 'capture');

    expect($failed)->toBe(8, json_encode($first))
        ->and($code)->toBe(5, json_encode($json))
        ->and($json['data']['resumed_revert'])->toBeTrue()
        ->and(safetyEnv($this->config))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_USERNAME' => SAFETY_USER, 'MAIL_PASSWORD' => SAFETY_PASSWORD])
        ->and($this->config->instance->directory->exists(ConfigRollout::CREDENTIALS_STASH))->toBeFalse();
});

it('drops the account when only the SMTP server changes, with a warning, so it never reaches another server', function (): void {
    safetySmtp($this->config);

    [$code, $json] = $this->config->set('mail', 'smtp', ['--smtp-host' => 'relay.example.net']);

    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('smtp_credentials_dropped')
        ->and(safetyEnv($this->config))->toMatchArray(['MAIL_HOST' => 'relay.example.net'])
        ->and(safetyEnv($this->config))->not->toHaveKey('MAIL_USERNAME')->not->toHaveKey('MAIL_PASSWORD')
        ->and(json_encode($json))->not->toContain(SAFETY_USER);
});

it('keeps the account for another port or encryption of the same server', function (): void {
    safetySmtp($this->config);

    [$code, $json] = $this->config->set('mail', 'smtp', ['--smtp-encryption' => 'tls']);

    expect($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('smtp_credentials_dropped')
        ->and(safetyEnv($this->config))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_PORT' => '465', 'MAIL_USERNAME' => SAFETY_USER, 'MAIL_PASSWORD' => SAFETY_PASSWORD]);
});

it('brings the account back with its server when a move to another server without one is reverted', function (): void {
    safetySmtp($this->config);
    $this->config->instance->oneOffs['instance:mail-test'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:mail-test', 'status' => 'failed',
        'errors' => [['code' => 'test_mail_failed', 'message' => '530 authentication required', 'hint' => null, 'retryable' => false]]]);

    [$code, $json] = $this->config->set('mail', 'smtp', ['--smtp-host' => 'relay.example.net']);

    expect($code)->toBe(5, json_encode($json))
        ->and(safetyEnv($this->config))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_USERNAME' => SAFETY_USER, 'MAIL_PASSWORD' => SAFETY_PASSWORD]);
});
