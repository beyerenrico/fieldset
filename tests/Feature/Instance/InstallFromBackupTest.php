<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\MigrationSet;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\FromBackupFixture;

/*
 * `fsa install --from-backup` (R42, R60, F6, plan U17, ADR 0085) against the recording Docker gateway: the backup's
 * portable settings and the host's own, capture mail and paused webhooks, the backup's APP keys, the restore steps in
 * install phases, a pending certificate, the 1.0 format with its forced safe values, refusals and a resumed install.
 */

beforeEach(function (): void {
    $this->move = new FromBackupFixture;
});

afterEach(function (): void {
    $this->move->cleanup();
});

it('moves a local backup to a new instance: its data, its portable settings, capture mail and paused webhooks', function (): void {
    $backup = $this->move->backup('local', ['backup_retention' => 9, 'time_zone' => 'Europe/Berlin', 'mail' => 'smtp', 'smtp_host' => 'mail.example.com', 'smtp_port' => 587, 'mail_from_address' => 'forms@example.com']);

    [$code, $json] = $this->move->install($backup, ['--app-key-file' => $this->move->keyFile()]);
    $state = $this->move->instance->state();
    $settings = InstanceSettings::of($state);
    $app = new EnvFiles($this->move->instance->directory)->read('app');
    $runtime = json_decode((string) $this->move->instance->directory->read('runtime/runtime.json'), true);
    $finish = $this->move->instance->oneOffCalls('instance:restore-finish')[0];
    $history = array_column($state->history(), 'event');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['mode' => 'local', 'url' => 'http://localhost:8080', 'webhooks' => 'paused', 'sign_in_link' => null])
        ->and($json['data']['from_backup'])->toMatchArray(['backup' => 'manual-20261019T120000Z', 'format' => 'fsa', 'authenticated' => true, 'epoch' => 2])
        ->and($json['data']['pending_mail'])->toMatchArray(['host' => 'mail.example.com', 'port' => 587])
        ->and($json['data']['confirm_commands'][0])->toBe('fsa config set webhooks on')
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('mail_captured_after_move', 'webhooks_paused', 'no_sign_in_link')
        ->and($settings->capturesMail())->toBeTrue()->and($settings->webhooksPaused())->toBeTrue()
        ->and($runtime['webhooks'])->toBe(['paused' => true])
        ->and($settings->backupRetention())->toBe(9)->and($settings->timeZone())->toBe('Europe/Berlin')->and($settings->mailFromAddress())->toBe('forms@example.com')
        ->and($settings->storageVolume())->toStartWith($state->project().'_app-storage-r')
        ->and($app['APP_KEY'])->toBe(BackupSetFixture::APP_KEY)
        ->and($app)->not->toHaveKey('MAIL_PASSWORD')->and($app['FSA_MAIL_CAPTURE'] ?? null)->toBe('true')->and($app['MAIL_HOST'] ?? null)->toBe('mailpit')
        ->and($this->move->instance->oneOffCalls('app:install'))->toBe([])
        ->and($this->move->instance->oneOffCalls('migrate'))->toHaveCount(1)
        ->and($finish['command'])->not->toContain('--source=form_schema')->and($finish['command'])->toContain('--backup=manual-20261019T120000Z')
        ->and($this->move->actions())->toBe(['list', 'drop', 'create', 'swap', 'drop'])
        ->and($this->move->databases)->toEqualCanonicalizing(['form_schema', 'postgres'])
        ->and($history)->toContain('installed', 'installed_from_backup')
        ->and(glob($this->move->instance->directory->root.'/backups/.restore-*'))->toBe([])
        ->and(json_encode($json))->not->toContain(BackupSetFixture::APP_KEY);
    // The compose file declares the fresh volume as an existing one, and every Compose call checks that it exists (J08).
    $methods = $this->move->instance->docker->methods();
    expect(array_search('createVolume', $methods, true))->toBeLessThan(array_search('composeUp', $methods, true))
        ->and(array_search('createVolume', $methods, true))->toBeLessThan(array_search('composeRun', $methods, true))
        ->and($this->move->instance->docker->called('createVolume'))->toHaveCount(1);
});

it('takes a new --domain whose DNS does not point here yet as "certificate pending" and verifies internally', function (): void {
    $backup = $this->move->backup('domain', ['domain' => 'old.example.com']);

    [$code, $json] = $this->move->install($backup, ['--app-key-file' => $this->move->keyFile(), '--domain' => 'new.example.com']);
    $state = $this->move->instance->state();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['mode' => 'domain', 'url' => 'https://new.example.com'])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('certificate_pending')
        ->and($state->setting('domain'))->toBe('new.example.com')
        ->and(array_filter($this->move->curls, static fn (string $url): bool => str_starts_with($url, 'https://')))->toBe([]);
});

it('keeps the backup\'s own domain without a mode flag and checks the edge when DNS points here', function (): void {
    $this->move->pointHere('forms.example.com');
    $backup = $this->move->backup('domain', ['domain' => 'forms.example.com', 'acme_email' => 'ops@example.com']);

    [$code, $json] = $this->move->install($backup, ['--app-key-file' => $this->move->keyFile()]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['mode' => 'domain', 'url' => 'https://forms.example.com'])
        ->and($this->move->instance->state()->setting('acme_email'))->toBe('ops@example.com')
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('certificate_pending')
        ->and($this->move->curls)->toContain('https://forms.example.com/up');
});

it('installs from a 1.0 backup and its env only with --accept-unauthenticated, forcing the safe values', function (): void {
    $env = 'APP_KEY='.BackupSetFixture::OTHER_KEY."\nAPP_DEBUG=true\nSSO_ALLOW_INSECURE_IDP=true\nTRUSTED_PROXIES=*\nMAIL_HOST=smtp.legacy.test\nMAIL_PASSWORD=hunter2hunter2\nMAIL_FROM_ADDRESS=old@legacy.test\n";
    $legacy = BackupSetFixture::legacy($this->move->outside.'/20261001T020000Z', $env);

    [$refused, $refusedJson] = $this->move->install($legacy);
    [$code, $json] = $this->move->install($legacy, ['--accept-unauthenticated' => true]);
    $app = new EnvFiles($this->move->instance->directory)->read('app');
    $create = array_values(array_filter($this->move->databaseCalls, static fn (array $call): bool => $call['action'] === 'create'))[0];

    expect($refused)->toBe(2)->and($refusedJson['errors'][0]['code'])->toBe('backup_unauthenticated')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($app['APP_KEY'])->toBe(BackupSetFixture::OTHER_KEY)
        ->and($app['APP_DEBUG'])->toBe('false')->and($app['SSO_ALLOW_INSECURE_IDP'])->toBe('false')->and($app['SSO_DNS_DRIVER'])->toBe('dns')
        ->and($app)->not->toHaveKey('TRUSTED_PROXIES')->not->toHaveKey('MAIL_PASSWORD')
        ->and(InstanceSettings::of($this->move->instance->state())->mailFromAddress())->toBe('old@legacy.test')
        ->and($json['data']['pending_mail']['host'] ?? null)->toBe('smtp.legacy.test')
        ->and(implode(' ', $create['options']))->toContain('--restricted-role=')
        ->and(array_search('audit', $this->move->actions(), true))->toBeLessThan(array_search('adopt', $this->move->actions(), true)) // review
        ->and($this->move->instance->oneOffCalls('migrate'))->toHaveCount(2) // the reference schema, then the restored data
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('backup_unauthenticated')
        ->and(json_encode($json))->not->toContain('hunter2');
});

it('uses the APP key inside an encrypted backup with secrets, and keeps the other listed keys as previous keys', function (): void {
    $dir = BackupSetFixture::fsa($this->move->outside.'/manual-20261019T130000Z', manifest: ['app_key_fingerprints' => [KeyFingerprint::of(BackupSetFixture::APP_KEY), KeyFingerprint::of(BackupSetFixture::OTHER_KEY)]],
        extra: ['secrets.env' => "APP_KEY='".BackupSetFixture::APP_KEY."'\nAPP_PREVIOUS_KEYS='".BackupSetFixture::OTHER_KEY."'\n"]);
    $file = BackupSetFixture::encrypt($dir);
    $identity = BackupSetFixture::identityFile($this->move->outside.'/identity.age');
    file_put_contents($this->move->outside.'/passphrase', BackupSetFixture::PASSPHRASE);

    [$unauthenticated, $unauthenticatedJson] = $this->move->install($file, ['--identity-file' => $identity, '--passphrase-file' => $this->move->outside.'/passphrase']);
    [$code, $json] = $this->move->install($file, ['--identity-file' => $identity, '--passphrase-file' => $this->move->outside.'/passphrase', '--accept-unauthenticated' => true]);
    $app = new EnvFiles($this->move->instance->directory)->read('app');

    expect($unauthenticated)->toBe(2)->and($unauthenticatedJson['errors'][0]['code'])->toBe('backup_unauthenticated')
        ->and($unauthenticatedJson['errors'][0]['hint'])->toContain('--app-key-file')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($app['APP_KEY'])->toBe(BackupSetFixture::APP_KEY)->and($app['APP_PREVIOUS_KEYS'] ?? null)->toBe(BackupSetFixture::OTHER_KEY)
        ->and(json_encode($json))->not->toContain(BackupSetFixture::PASSPHRASE)->not->toContain(BackupSetFixture::OTHER_KEY);
});

it('refuses the flags a move does not take, and --from-backup flags without it', function (bool $withBackup, array $flags, string $error): void {
    $backup = $this->move->backup();

    [$code, $json] = $withBackup
        ? $this->move->install($backup, $flags)
        : $this->move->instance->run('fsa:install', ['--org' => 'Acme Forms', '--owner-email' => 'owner@example.com', ...$flags]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe($error)
        ->and($this->move->instance->directory->hasState())->toBeFalse();
})->with([
    'an organization' => [true, ['--org' => 'Acme'], 'flag_not_applicable'],
    'demo data' => [true, ['--demo' => true], 'flag_not_applicable'],
    'an SMTP server' => [true, ['--smtp-host' => 'mail.example.com'], 'flag_not_applicable'],
    'an SMTP account' => [true, ['--smtp-username' => 'forms'], 'flag_not_applicable'],
    'an identity without a backup' => [false, ['--identity-file' => '/tmp/identity.age'], 'invalid_flag'],
    'accepting without a backup' => [false, ['--accept-unauthenticated' => true], 'invalid_flag'],
]);

it('needs proxy flags for a backup of an instance behind a proxy', function (): void {
    [$code, $json] = $this->move->install($this->move->backup('proxy'), ['--app-key-file' => $this->move->keyFile()]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('proxy_flags_required');
});

it('refuses a backup of a newer version (exit 7) and one whose APP key is unknown, before writing the instance', function (): void {
    $this->move->migrations = [...MigrationSet::image(base_path('database/migrations')), '2099_01_01_000000_from_the_future'];
    [$newer, $newerJson] = $this->move->install($this->move->backup(), ['--app-key-file' => $this->move->keyFile()]);
    $this->move->migrations = null;
    [$keyless, $keylessJson] = $this->move->install($this->move->backup(name: 'manual-20261019T140000Z'), ['--accept-unauthenticated' => true]);

    expect($newer)->toBe(7)->and($newerJson['errors'][0]['code'])->toBe('backup_newer')
        ->and($keyless)->toBe(2)->and($keylessJson['errors'][0]['code'])->toBe('app_key_unknown')
        ->and($this->move->instance->directory->hasState())->toBeFalse()
        ->and(new OperationJournal($this->move->instance->directory)->current())->toBeNull();
});

it('resumes after a failed pg_restore, then changes nothing on a second run with the same backup', function (): void {
    $backup = $this->move->backup();
    $this->move->pgRestore = new ProcessOutcome(1, '', 'pg_restore: error: connection lost');

    [$failed, $failedJson] = $this->move->install($backup, ['--app-key-file' => $this->move->keyFile()]);
    $this->move->pgRestore = null;
    [$resumed, $resumedJson] = $this->move->install($backup, ['--app-key-file' => $this->move->keyFile()]);
    [$again, $againJson] = $this->move->install($backup, ['--app-key-file' => $this->move->keyFile()]);
    [$other, $otherJson] = $this->move->install($this->move->backup(name: 'manual-20261019T150000Z'), ['--app-key-file' => $this->move->keyFile()]);

    expect($failed)->toBe(4)->and($failedJson['errors'][0]['code'])->toBe('pg_restore_failed')
        ->and($resumed)->toBe(0, (string) json_encode($resumedJson))
        ->and($this->move->databases)->toEqualCanonicalizing(['form_schema', 'postgres'])
        ->and($again)->toBe(0)->and($againJson['changed'])->toBeFalse()
        ->and($other)->toBe(2)->and($otherJson['errors'][0]['code'])->toBe('use_restore');
});

it('issues a sign-in link for an existing admin given with --owner-email', function (): void {
    [$code, $json] = $this->move->install($this->move->backup(), ['--app-key-file' => $this->move->keyFile(), '--owner-email' => 'owner@example.com']);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['sign_in_link']['delivered'] ?? null)->toBe('secrets')
        ->and($json['secrets'][0]['name'] ?? null)->toBe('sign_in_link')
        ->and($this->move->instance->oneOffCalls('identity:login-link'))->toHaveCount(1);
});
