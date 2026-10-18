<?php

declare(strict_types=1);

use App\Domain\Instance\Console\ConfigCommand;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\ConfigFixture;
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
