<?php

declare(strict_types=1);

use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\Console\ConfigCommand;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11 (§7, R31, R32, §10; ADR 0077): `fsa config show` (effective settings, secrets redacted, outbound contacts)
 * and the command's usage rules: unknown keys, keys of later pre-releases (the update check: 2.0.0), an instance that is not
 * installed, a running operation (exit 9) and an unfinished one of another kind.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('shows every setting of a local instance with secrets redacted and no outbound contact by default', function (): void {
    $config = new ConfigFixture;
    $secrets = new EnvFiles($config->instance->directory)->secretValues();
    $mark = $config->mark();

    [$code, $json] = $config->show();

    $raw = json_encode($json);
    $contacts = collect($json['data']['outbound_contacts']['features'])->keyBy('feature');
    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['data'])->toMatchArray(['mode' => 'local', 'url' => 'http://localhost:8080', 'version' => InstanceCommandFixture::VERSION])
        ->and($json['data']['settings'])->toMatchArray(['port' => 8080, 'mail_ui_port' => 8025, 'domain' => null, 'acme' => null, 'proxy' => null, 'trusted_proxies' => [], 'size' => 'small'])
        ->and($json['data']['settings']['mail'])->toMatchArray(['mode' => 'capture', 'catcher_url' => 'http://localhost:8025', 'smtp' => null])
        ->and($json['data']['secrets'])->toMatchArray(['app_key' => SecretRedactor::REPLACEMENT, 'database_password' => SecretRedactor::REPLACEMENT, 'catcher_password' => SecretRedactor::REPLACEMENT])
        ->and(collect($secrets)->contains(fn (string $secret): bool => str_contains($raw, $secret)))->toBeFalse()
        ->and($contacts->filter(fn (array $feature): bool => $feature['active'])->all())->toBe([])
        ->and($contacts['upgrades']['contacts'])->toContain(InstanceCommandFixture::MIRROR, 'https://github.com/beyerenrico/form-schema-app/releases')
        ->and($contacts['acme']['contacts'])->toBe([])
        ->and(collect($json['data']['keys'])->firstWhere('key', 'mail'))->toMatchArray(['value' => 'capture', 'available' => true, 'settable_now' => true])
        ->and(collect($json['data']['keys'])->firstWhere('key', 'backup-schedule'))->toMatchArray(['value' => '03:30', 'available' => true, 'settable_now' => true]) // arrived with U16 (ADR 0084)
        ->and(collect($json['data']['keys'])->firstWhere('key', 'update-check'))->toMatchArray(['available' => false, 'arrives_with' => '2.0.0'])
        ->and(collect($json['data']['keys'])->firstWhere('key', 'port'))->toMatchArray(['value' => 8080, 'available' => true, 'changes_url' => true])
        ->and($config->methodsSince($mark))->toBe([]);
});

it('shows domain mode with its ACME directory as the one active outbound contact', function (): void {
    $config = new ConfigFixture;
    $config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true, '--acme-staging' => true, '--acme-email' => 'ops@example.com']);

    [, $json] = $config->show();

    $contacts = collect($json['data']['outbound_contacts']['features'])->keyBy('feature');
    expect($json['data']['settings'])->toMatchArray(['port' => null, 'mail_ui_port' => null, 'domain' => ConfigFixture::DOMAIN])
        ->and($json['data']['settings']['acme'])->toMatchArray(['email' => 'ops@example.com', 'ca' => InstanceSettings::ACME_STAGING, 'staging' => true])
        ->and($json['data']['settings']['trusted_proxies'])->toHaveCount(1)
        ->and($contacts['acme'])->toMatchArray(['active' => true, 'contacts' => [InstanceSettings::ACME_STAGING]]);
});

it('says when later settings arrive and lists the keys for an unknown one, exit 2', function (string $key, string $error, string $text): void {
    [$code, $json] = new ConfigFixture()->set($key, 'x');

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['message'].' '.$json['errors'][0]['hint'])->toContain($text);
})->with([
    'update check' => ['update-check', 'setting_not_available', '2.0.0'],
    'backup schedule (arrived with U16, ADR 0084)' => ['backup-schedule', 'invalid_value', 'HH:MM'],
    'unknown' => ['colour', 'unknown_setting', 'domain, url, port, trusted-proxy, mode, acme-email, acme-ca, mail, phone-https, lan-address, backup-schedule, backup-retention, backup-encryption, webhooks, operator-email'],
    'invalid mail value' => ['mail', 'invalid_value', 'capture'],
]);

it('refuses bad usage with exit 2', function (array $arguments): void {
    $fixture = new ConfigFixture;

    [$code, $json] = $fixture->instance->run('fsa:config', $arguments);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('invalid_usage');
})->with([
    'other action' => [['action' => 'delete']],
    'set without value' => [['action' => 'set', 'key' => 'port']],
    'show with key' => [['action' => 'show', 'key' => 'port']],
]);

it('refuses a secret passed as a flag value before parsing (R50)', function (): void {
    [$code, $json] = new ConfigFixture()->set('mail', 'smtp', ['--smtp-password' => 'hunter2hunter2']);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('secret_in_argument')->and(json_encode($json))->not->toContain('hunter2hunter2');
});

it('needs an installed instance', function (): void {
    $fixture = new InstanceCommandFixture;

    [$code, $json] = $fixture->run('fsa:config', ['action' => 'set', 'key' => 'port', 'value' => '8090']);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('not_installed');
});

it('exits 9 while another operation holds the lock, and for an unfinished operation of another kind', function (): void {
    $config = new ConfigFixture;
    $journal = new OperationJournal($config->instance->directory);
    $journal->begin('upgrade', 501);
    $journal->startPhase('backup');

    [$unfinished, $json] = $config->set('port', '8090', ['--yes' => true]);
    expect($unfinished)->toBe(9)->and($json['errors'][0]['code'])->toBe('operation_unfinished');

    $config->instance->docker->withRunningContainer(str_repeat('b', 64), '2026-10-18T10:00:00Z');
    $lock = app(InstanceLock::class);
    $lock->acquire(TemporaryInstance::holder('b', '2026-10-18T10:00:00Z'), 'upgrade');

    [$locked, $lockedJson] = $config->set('port', '8090', ['--yes' => true]);
    expect($locked)->toBe(9)->and($lockedJson['errors'][0]['code'])->toBe('instance_locked');
});

it('names every settable key in the help of its key argument (M11 gate: phone-https and lan-address were missing)', function (): void {
    $help = app(ConfigCommand::class)->getDefinition()->getArgument('key')->getDescription();

    foreach (ConfigKeys::keys() as $key) {
        expect($help)->toMatch('/\b'.preg_quote($key, '/').'\b/');
    }
});
