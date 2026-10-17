<?php

declare(strict_types=1);

/* M8-T12: the production template never carries development-only settings. */

function envTemplate(string $file): array
{
    $values = [];
    foreach (file(base_path($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (! str_starts_with($line, '#') && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }
    }

    return $values;
}

it('keeps the production template production-safe', function (): void {
    $prod = envTemplate('.env.production.example');

    expect($prod)->toMatchArray([
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'COMPOSE_FILE' => 'compose.yaml',
        'SSO_ALLOW_INSECURE_IDP' => 'false', 'SSO_DNS_DRIVER' => 'dns', 'SESSION_SECURE_COOKIE' => 'true',
        'MESSAGING_SMTP_ALLOW_PRIVATE_HOSTS' => 'false',
    ])->and($prod['APP_URL'])->toStartWith('https://')
        ->and(array_keys($prod))->not->toContain('FORWARD_DB_PORT');
});

it('lists the same settings in both templates (production drops only dev port forwards)', function (): void {
    $dev = array_keys(envTemplate('.env.example'));
    $prod = array_keys(envTemplate('.env.production.example'));

    expect(array_values(array_diff($dev, $prod)))->each->toStartWith('FORWARD_')
        ->and(array_values(array_diff($prod, $dev)))->toBe([]);
});
