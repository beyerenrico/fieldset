<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InvalidState;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U3 / KTD7: the generated secrets, which live only in the per-service env files.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('generates a Laravel APP_KEY and long random passwords', function (): void {
    $a = InstanceSecrets::generate();
    $b = InstanceSecrets::generate();

    expect($a->appKey)->toMatch('/^base64:[A-Za-z0-9+\/]{43}=$/')
        ->and(strlen((string) base64_decode(substr($a->appKey, 7), true)))->toBe(32)
        ->and($a->dbPassword)->toMatch('/^[0-9a-f]{48}$/')
        ->and($a->valkeyPassword)->toMatch('/^[0-9a-f]{48}$/')
        ->and($a->catcherPassword)->toMatch('/^[0-9a-f]{48}$/')
        ->and($a->backupDbPassword)->toMatch('/^[0-9a-f]{48}$/')
        ->and($a->smtpUsername)->toBeNull()
        ->and($a->dbPassword)->not->toBe($b->dbPassword)
        ->and($a->appKey)->not->toBe($b->appKey);
});

it('reads back the secrets it rendered into the env files', function (): void {
    $directory = TemporaryInstance::directory();
    $secrets = ComposeFixtures::secrets()->withSmtpCredentials('mailer', ComposeFixtures::SMTP_PASSWORD);
    $state = ComposeFixtures::state(ComposeFixtures::smtpSettings(), $directory->root);
    (new ComposeRenderer)->write($directory, $state, $secrets);

    $read = InstanceSecrets::fromEnvFiles(new EnvFiles($directory));

    expect($read)->not->toBeNull()
        ->and($read?->appKey)->toBe(ComposeFixtures::APP_KEY)
        ->and($read?->dbPassword)->toBe(ComposeFixtures::DB_PASSWORD)
        ->and($read?->valkeyPassword)->toBe(ComposeFixtures::VALKEY_PASSWORD)
        ->and($read?->smtpUsername)->toBe('mailer')
        ->and($read?->smtpPassword)->toBe(ComposeFixtures::SMTP_PASSWORD)
        ->and($read?->catcherPassword)->toMatch('/^[0-9a-f]{48}$/');
});

it('keeps the catcher password across renders in capture mode', function (): void {
    $directory = TemporaryInstance::directory();
    (new ComposeRenderer)->write($directory, ComposeFixtures::state(path: $directory->root), ComposeFixtures::secrets());

    $read = InstanceSecrets::fromEnvFiles(new EnvFiles($directory));

    expect($read?->catcherPassword)->toBe(ComposeFixtures::CATCHER_PASSWORD)
        ->and($read?->smtpUsername)->toBeNull()
        ->and($read?->smtpPassword)->toBeNull();
});

it('returns null without an app env file holding APP_KEY and the database password', function (): void {
    $directory = TemporaryInstance::directory();
    $env = new EnvFiles($directory);

    expect(InstanceSecrets::fromEnvFiles($env))->toBeNull();

    $env->write('app', ['APP_KEY' => ComposeFixtures::APP_KEY]);
    expect(InstanceSecrets::fromEnvFiles($env))->toBeNull();
});

it('never shows its values in var_dump or debug output', function (): void {
    $dump = print_r(ComposeFixtures::secrets(), true);

    expect($dump)->not->toContain(ComposeFixtures::DB_PASSWORD)->not->toContain(ComposeFixtures::APP_KEY)->toContain('redacted');
});

it('reads settings with their defaults', function (): void {
    $settings = InstanceSettings::of(ComposeFixtures::state());

    expect($settings->port())->toBe(8080)
        ->and($settings->mailUiPort())->toBe(8025)
        ->and($settings->capturesMail())->toBeTrue()
        ->and($settings->size())->toBe('small')
        ->and($settings->dbDatabase())->toBe('fieldset')
        ->and($settings->dbUsername())->toBe('fieldset')
        ->and($settings->smtpPort())->toBe(587)
        ->and($settings->smtpScheme())->toBeNull()
        ->and($settings->mailFromAddress())->toBe('forms@localhost')
        ->and($settings->mailFromName())->toBe('form-schema-app')
        ->and($settings->extraHosts())->toBe([])
        ->and($settings->instanceUrl())->toBe('http://localhost:8080')
        ->and($settings->localUrl())->toBe('http://localhost:8080');
});

it('derives the sender from the domain and needs a URL outside local mode', function (): void {
    $domain = ComposeFixtures::state(['domain' => 'forms.example.com'])->withMode('domain')->withUrl('https://forms.example.com');
    $proxy = ComposeFixtures::state()->withMode('proxy');

    expect(InstanceSettings::of($domain)->mailFromAddress())->toBe('forms@forms.example.com')
        ->and(InstanceSettings::of($domain)->localUrl())->toBeNull()
        ->and(InstanceSettings::of($domain)->instanceUrl())->toBe('https://forms.example.com')
        ->and(fn () => InstanceSettings::of($proxy)->instanceUrl())->toThrow(InvalidState::class, 'URL');
});
