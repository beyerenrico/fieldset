<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\BackupIntake;
use App\Domain\Instance\Restore\BackupPreparer;
use App\Domain\Instance\Restore\BackupSet;
use App\Domain\Instance\Restore\LegacyEnv;
use App\Domain\Instance\Restore\MigrationSet;
use App\Domain\Instance\Restore\PreparedBackup;
use App\Domain\Instance\Restore\RestoreChecks;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\Restore\RestoreSpace;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\AdoptFixture;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\InstanceCommandFixture;

/*
 * `fsa adopt <checkout>` (R46 as changed by R60, F7, KTD20, plan U19, ADR 0087) against the recording Docker gateway: the
 * allow-list of the 1.0 `.env` and the forced safe values, the mode inferred from APP_URL and APP_PORT, the KTD20 order,
 * the 1.0-format backup, the refusals (exit 7), the space check, a repeated adopt, uninstall's final backup and writes
 * since adopt. Failures, the drain, resume and `--abandon`: AdoptRecoveryTest.
 */

beforeEach(function (): void {
    $this->adopt = new AdoptFixture;
});

afterEach(function (): void {
    $this->adopt->cleanup();
});

/** @return list<string> the backup directories adopt wrote */
function adoptBackups(AdoptFixture $adopt): array
{
    return array_values(array_map(basename(...), glob($adopt->instance->directory->root.'/backups/safety-adopt-*') ?: []));
}

it('adopts a local 1.0 install: its APP key, database names and sender, capture mail and the forced safe values', function (): void {
    [$code, $json] = $this->adopt->adopt();
    $state = $this->adopt->instance->state();
    $settings = InstanceSettings::of($state);
    $app = new EnvFiles($this->adopt->instance->directory)->read('app');
    $history = array_column($state->history(), 'event');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['mode' => 'local', 'url' => 'http://localhost:8080', 'port' => 8080])
        ->and($json['data']['adopted'])->toMatchArray(['checkout' => $this->adopt->checkout, 'project' => 'form-schema-app', 'version' => '1.0.0'])
        ->and($json['data']['adopted']['backup'])->toStartWith('safety-adopt-')
        ->and($json['data']['adopted']['verified']['counts']['submissions'])->toBe(41)
        ->and($json['data']['adopted']['verified']['amcheck'])->toBe(['users_email_unique', 'organizations_slug_unique'])
        ->and($json['data']['pending_mail'])->toMatchArray(['host' => 'smtp.legacy.test', 'port' => 587])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('mail_captured_after_adopt')
        ->and($app['APP_KEY'])->toBe(AdoptFixture::APP_KEY)
        ->and($app['APP_DEBUG'])->toBe('false')->and($app['SSO_ALLOW_INSECURE_IDP'])->toBe('false')->and($app['SSO_DNS_DRIVER'])->toBe('dns')
        ->and($app)->not->toHaveKey('TRUSTED_PROXIES')->not->toHaveKey('MAIL_PASSWORD')
        ->and($app['DB_PASSWORD'])->not->toBe('legacy-db-secret-1')
        ->and($settings->dbDatabase())->toBe('form_schema')->and($settings->dbUsername())->toBe('form_schema')
        ->and($settings->mailFromAddress())->toBe('forms@legacy.test')->and($settings->capturesMail())->toBeTrue()
        ->and($history)->toContain('installed', 'adopted')
        ->and($this->adopt->instance->oneOffCalls('migrate'))->toHaveCount(1)
        ->and($this->adopt->instance->oneOffCalls('app:install'))->toBe([])
        ->and(json_encode($json))->not->toContain(AdoptFixture::APP_KEY)->not->toContain('legacy-db-secret-1')->not->toContain('legacy-mail-secret-1')
        ->and(app(OperationJournal::class)->current())->toBeNull();
});

it('follows the KTD20 order: stop and drain 1.0, back it up, stop its Postgres, copy, verify, then start 2.0, checking 1.0 stayed stopped', function (): void {
    [$code] = $this->adopt->adopt();
    $docker = $this->adopt->instance->docker;
    $steps = [];
    foreach ($docker->calls as $call) {
        $step = match ($call['method']) {
            'stopContainers' => 'stop:'.implode(',', array_map(fn (string $id): string => (string) preg_replace('/^legacy|\d+$/', '', $id), $call['args']['ids'])),
            'startContainers' => 'start-1.0',
            'runContainerToFile' => 'file:'.$call['args']['spec']->entrypoint,
            'runContainer' => in_array($call['args']['spec']->entrypoint, ['psql', 'cp', 'pg_controldata', 'valkey-cli', 'pg_restore'], true) ? 'run:'.$call['args']['spec']->entrypoint : null,
            'composeUp' => 'up:'.implode(',', $call['args']['services']),
            'composeRun' => ($call['args']['command'][2] ?? $call['args']['command'][0] ?? '') === 'migrate' ? 'migrate' : null,
            default => null,
        };
        if ($step !== null && end($steps) !== $step) {
            $steps[] = $step;
        }
    }

    expect($code)->toBe(0)
        ->and($steps)->toBe([
            'stop:app,scheduler', 'run:valkey-cli', 'stop:queue', 'file:pg_dump', 'run:pg_restore', 'run:psql', 'file:tar',
            'stop:postgres', 'stop:redis,gotenberg', 'run:pg_controldata', 'run:cp', 'run:pg_controldata', 'up:postgres', 'run:psql',
            'up:postgres,redis', 'migrate', 'run:psql', 'run:pg_controldata', 'up:', 'run:pg_controldata', // checked again after the start (review A1)
        ]);
});

it('writes the backup in the 1.0 format with only the allow-listed env values but no APP key, and never writes the 1.0 volumes', function (): void {
    [$code, $json] = $this->adopt->adopt();
    $dir = $json['data']['adopted']['backup_path'];
    $env = (string) file_get_contents($dir.'/env');
    $manifest = BackupSet::legacyManifest($dir);
    $legacyMounts = array_merge(...array_map(static fn (ContainerSpec $spec): array => array_values(array_filter($spec->volumes, static fn (string $volume): bool => str_starts_with($volume, AdoptFixture::PROJECT.'_'))),
        [...$this->adopt->instance->runSpecs(), ...array_map(static fn (array $call): ContainerSpec => $call['spec'], $this->adopt->instance->docker->called('runContainerToFile'))]));
    $dump = $this->adopt->instance->docker->called('runContainerToFile')[0]['spec'];

    expect($code)->toBe(0)
        ->and(adoptBackups($this->adopt))->toHaveCount(1)
        ->and(array_values(array_diff(scandir($dir) ?: [], ['.', '..'])))->toBe(['SHA256SUMS', 'database.dump', 'env', 'manifest.txt', 'storage.tar.gz'])
        ->and(BackupChecksums::verifySums($dir))->toBe([])
        ->and(BackupSet::detect($dir)['format'])->toBe(BackupSet::LEGACY)
        ->and(LegacyEnv::read($env))->toEqual(['APP_URL' => 'http://localhost:8080', 'MAIL_FROM_ADDRESS' => 'forms@legacy.test', 'MAIL_FROM_NAME' => 'Legacy Forms', 'MAIL_HOST' => 'smtp.legacy.test', 'MAIL_PORT' => '587'])
        ->and($env)->not->toContain('DB_PASSWORD')->not->toContain('legacy-mail-secret-1')->not->toContain('SSO_')->not->toContain(AdoptFixture::APP_KEY)->toContain('--app-key-file') // review S1
        ->and($manifest)->toMatchArray(['migrations' => '120', 'last_migration' => '2026_10_25_100000_v1_last', 'submissions' => '41', 'users' => '7', 'storage' => 'included', 'env' => 'included', 'source' => 'fsa adopt',
            'app_key_fingerprints' => AppKeys::of([AdoptFixture::APP_KEY])->fingerprints()[0]])
        ->and(substr(sprintf('%o', fileperms($dir)), -3))->toBe('700')->and(substr(sprintf('%o', fileperms($dir.'/env')), -3))->toBe('600')
        ->and($legacyMounts)->not->toBeEmpty()
        ->and(array_filter($legacyMounts, static fn (string $mount): bool => ! str_ends_with($mount, ':ro')))->toBe([])
        ->and($dump->network)->toBe('container:'.$this->adopt->legacyId('postgres'))
        ->and(implode(' ', $dump->command))->toContain('--format=custom')->not->toContain('legacy-db-secret-1');
});

it('gives the copied 1.0 role the instance\'s password as a SCRAM verifier on stdin, never the plain password', function (): void {
    [$code] = $this->adopt->adopt();
    $password = new EnvFiles($this->adopt->instance->directory)->read('app')['DB_PASSWORD'];
    $alter = array_values(array_filter($this->adopt->sql, static fn (string $sql): bool => str_contains($sql, 'ALTER ROLE')));
    $psql = $this->adopt->specs('psql');

    expect($code)->toBe(0)
        ->and($alter)->toHaveCount(1)
        ->and($alter[0])->toStartWith('ALTER ROLE "form_schema" WITH PASSWORD \'SCRAM-SHA-256$4096:')->not->toContain($password)
        ->and(implode(' ', array_merge(...array_map(static fn (ContainerSpec $spec): array => $spec->command, $psql))))->not->toContain('PASSWORD');
});

it('infers the mode from APP_URL and APP_PORT', function (string $url, string $port, string $mode, ?string $setup, string $expectedUrl): void {
    $this->adopt->cleanup();
    $this->adopt = new AdoptFixture(AdoptFixture::env(['APP_URL' => $url, 'APP_PORT' => $port]));

    [$code, $json] = $this->adopt->adopt();
    $settings = InstanceSettings::of($this->adopt->instance->state());

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['mode' => $mode, 'url' => $expectedUrl, 'port' => (int) $port])
        ->and($settings->proxy())->toBe($setup);
})->with([
    'loopback URL: local mode' => ['http://localhost:8080', '8080', 'local', null, 'http://localhost:8080'],
    'loopback address, other port' => ['http://127.0.0.1:9090', '9090', 'local', null, 'http://localhost:9090'],
    'public https URL: behind the host\'s proxy' => ['https://forms.example.com', '8081', 'proxy', 'port', 'https://forms.example.com'],
]);

it('refuses what it cannot adopt with exit 7 and the reason, before 1.0 is touched', function (Closure $arrange, string $error): void {
    $arrange($this->adopt);

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(7, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($json['errors'][0]['hint'])->not->toBeEmpty()
        ->and($json['errors'][0]['hint'])->not->toContain('fsa install --from-backup') // the shim runs no install for another directory (gate review DOC14)
        ->and($this->adopt->instance->docker->called('stopContainers'))->toBe([])
        ->and($this->adopt->instance->directory->hasState())->toBeFalse();
})->with([
    'COMPOSE_FILE override' => [fn (AdoptFixture $a) => file_put_contents($a->checkout.'/.env', AdoptFixture::env(['COMPOSE_FILE' => 'compose.yaml:compose.dev.yaml'])), 'compose_file_override'],
    'an override file without COMPOSE_FILE' => [function (AdoptFixture $a): void {
        file_put_contents($a->checkout.'/.env', AdoptFixture::env(['COMPOSE_FILE' => null]));
        file_put_contents($a->checkout.'/compose.override.yaml', "services: {}\n");
    }, 'compose_file_override'],
    'started with another Compose file' => [fn (AdoptFixture $a) => $a->withLegacyStack(extraLabels: ['com.docker.compose.project.config_files' => $a->checkout.'/compose.yaml,'.$a->checkout.'/compose.dev.yaml']), 'compose_file_override'],
    'an external database' => [fn (AdoptFixture $a) => file_put_contents($a->checkout.'/compose.yaml', AdoptFixture::compose('db.example.com')), 'external_database'],
    'no pg-data volume' => [fn (AdoptFixture $a) => $a->instance->docker->containers[$a->legacyId('postgres')] = new ContainerInfo($a->legacyId('postgres'), 'pg', 'running', null, null, 'postgres:16-alpine', $a->instance->docker->containers[$a->legacyId('postgres')]->labels), 'external_database'],
    'a 2.x checkout' => [fn (AdoptFixture $a) => file_put_contents($a->checkout.'/package.json', '{"version":"2.0.0"}'), 'unsupported_version'],
    'no containers of the project' => [fn (AdoptFixture $a) => $a->instance->docker->containers = [], 'legacy_not_found'],
    'started from another directory' => [fn (AdoptFixture $a) => $a->withLegacyStack(extraLabels: ['com.docker.compose.project.working_dir' => '/srv/elsewhere']), 'checkout_mismatch'],
    'no APP_KEY' => [fn (AdoptFixture $a) => file_put_contents($a->checkout.'/.env', AdoptFixture::env(['APP_KEY' => ''])), 'app_key_missing'],
    'an fsa instance' => [fn (AdoptFixture $a) => $a->withLegacyStack(extraLabels: ['app.form-schema.instance' => '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192']), 'already_instance'],
    'a database name 2.0 cannot keep' => [fn (AdoptFixture $a) => file_put_contents($a->checkout.'/.env', AdoptFixture::env(['DB_DATABASE' => 'Forms-DB'])), 'unsupported_database_name'],
]);

it('refuses a path that is no checkout (exit 2) and asks before stopping 1.0 (exit 6)', function (): void {
    [$missing, $missingJson] = $this->adopt->instance->run('fsa:adopt', ['checkout' => $this->adopt->checkout.'/nope', '--yes' => true]);
    [$unconfirmed, $unconfirmedJson] = $this->adopt->instance->run('fsa:adopt', ['checkout' => $this->adopt->checkout]);
    [$none, $noneJson] = $this->adopt->instance->run('fsa:adopt', ['--yes' => true]);

    expect($missing)->toBe(2)->and($missingJson['errors'][0]['code'])->toBe('not_a_checkout')
        ->and($unconfirmed)->toBe(6)->and($unconfirmedJson['errors'][0]['message'])->toContain('Stop the 1.0 install')
        ->and($none)->toBe(2)->and($noneJson['errors'][0]['code'])->toBe('checkout_required')
        ->and($this->adopt->instance->docker->called('stopContainers'))->toBe([]);
});

it('refuses a checkout the launcher did not mount (exit 2)', function (): void {
    $this->adopt->instance->facts(['nonce' => '.fsa-nonce-0123456789abcdef', 'mounts' => []]);
    touch($this->adopt->instance->directory->root.'/.fsa-nonce-0123456789abcdef');

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('path_not_mounted');
});

it('skips the smoke test\'s mail when the 1.0 data has no operator, and says how to name one', function (): void {
    $this->adopt->instance->oneOffs['instance:mail-test'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:mail-test', 'status' => 'failed',
        'errors' => [['code' => 'invalid_input', 'message' => 'No active operator to send the test mail to: pass --test-to.', 'hint' => 'Pass --test-to <address>.', 'retryable' => false]]]);

    [$code, $json] = $this->adopt->adopt();
    $warning = collect($json['warnings'])->firstWhere('code', 'test_mail_skipped');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($warning['hint'] ?? '')->toContain('fsa config set operator-email');
});

it('checks for twice the 1.0 data\'s space first (exit 3, 1.0 keeps running)', function (): void {
    app()->instance(RestoreSpace::FREE_BYTES, static fn (): int => 1024 ** 3);

    [$code, $json] = $this->adopt->adopt();

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('low_disk_space')
        ->and($json['data'])->toMatchArray(['data_bytes' => 1073743872, 'required_bytes' => 2 * 1073743872 + 1024 ** 3])
        ->and($this->adopt->instance->docker->called('stopContainers'))->toBe([]);
});

it('changes nothing when the same checkout is adopted again, and refuses another one', function (): void {
    $this->adopt->adopt();
    $calls = count($this->adopt->instance->docker->calls);
    [$again, $againJson] = $this->adopt->adopt();
    $other = sys_get_temp_dir().'/fsa-test-checkout-other-'.bin2hex(random_bytes(3));
    [$refused, $refusedJson] = $this->adopt->instance->run('fsa:adopt', ['checkout' => $other, '--yes' => true]);

    expect($again)->toBe(0)->and($againJson['changed'])->toBeFalse()
        ->and($this->adopt->instance->docker->methodsSince($calls))->toBe([])
        ->and($refused)->toBe(2)->and($refusedJson['errors'][0]['code'])->toBe('already_installed');
});

it('records the baseline in the ledger, and uninstall takes a final backup and reports the writes since adopt', function (): void {
    $this->adopt->adopt();
    $entry = app(Ledger::class)->last('adopted');
    $this->adopt->uninstalling = true;
    $state = $this->adopt->instance->state();
    $this->adopt->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_pg-data', [Labels::INSTANCE => $state->uuid(), 'com.docker.compose.volume' => 'pg-data'])); // Compose made it
    $this->adopt->laterSnapshot = [...$this->adopt->snapshot, 'counts' => [...$this->adopt->snapshot['counts'], 'submissions' => 44], 'heads' => [...$this->adopt->snapshot['heads'], '1' => ['seq' => 185, 'hash' => 'cccc']]];

    [$code, $json] = $this->adopt->instance->run('fsa:uninstall', ['--yes' => true]);

    expect($entry)->toMatchArray(['action' => 'adopted', 'checkout' => $this->adopt->checkout, 'project' => 'form-schema-app', 'epoch' => 1])
        ->and($entry['baseline']['counts']['submissions'])->toBe(41)
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['final_backup']['dir'])->toStartWith('backups/final-')
        ->and($json['data']['kept'])->toBe(['backups'])
        ->and($json['data']['adopted'])->toMatchArray(['checkout' => $this->adopt->checkout, 'restart_1_0' => 'cd '.$this->adopt->checkout.' && docker compose up -d'])
        ->and($json['data']['adopted']['writes_since_adopt'])->toBe(['audit_entries' => 5, 'rows' => ['submissions' => 3], 'any' => true])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('writes_since_adopt', 'restart_1_0')
        ->and(collect($json['warnings'])->firstWhere('code', 'writes_since_adopt')['hint'] ?? '')->toContain('fsa install --from-backup')->not->toContain('pg_restore') // a 2.0 set since the M12 gate (review V3)
        ->and(adoptBackups($this->adopt))->toHaveCount(1);
});

it('uninstalls an adopted instance without a final backup with --no-backup, saying that the adopt backup goes too', function (): void {
    $this->adopt->adopt();
    [$prompt] = [$this->adopt->instance->run('fsa:uninstall', [])];
    [, $noBackupPrompt] = $this->adopt->instance->run('fsa:uninstall', ['--no-backup' => true]);
    [, $keepPrompt] = $this->adopt->instance->run('fsa:uninstall', ['--no-backup' => true, '--keep-backups' => true]);
    [$code, $json] = $this->adopt->instance->run('fsa:uninstall', ['--yes' => true, '--no-backup' => true]);

    expect($prompt[0])->toBe(6)->and($prompt[1]['errors'][0]['message'])->toContain('Write a final backup')->toContain($this->adopt->checkout)
        ->and($noBackupPrompt['errors'][0]['message'])->toContain('including backups/')->toContain('1.0 backup too')->toContain('No final backup is made')->toContain('is lost')->toContain($this->adopt->checkout) // review A8
        ->and($keepPrompt['errors'][0]['message'])->toContain('except backups/')->toContain('No final backup is made')->not->toContain('Write a final backup')
        ->and($code)->toBe(0)->and($json['data']['final_backup'])->toBeNull()->and($json['data']['backups_deleted'])->toBeTrue()
        ->and($json['data']['adopted']['restart_1_0'])->toContain('docker compose up -d');
});

it('says when the writes since adopt cannot be counted', function (): void {
    $this->adopt->adopt();
    unlink($this->adopt->instance->directory->root.'/backups/ledger.log');

    [$code, $json] = $this->adopt->instance->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(0)->and($json['data']['adopted']['writes_since_adopt'])->toBeNull()
        ->and(collect($json['warnings'])->firstWhere('code', 'writes_since_adopt_unknown')['hint'] ?? '')->toContain('final backup');
});

it('leaves a backup that a restore of this instance takes as its own, and no other 1.0 archive naming its key (review R1, S1)', function (): void {
    [, $json] = $this->adopt->adopt();
    app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
    $prepare = fn (string $set): PreparedBackup => new BackupPreparer(app(AgeCli::class), BackupSetFixture::tools())
        ->prepare($set, $this->adopt->instance->directory->ensureDirectory('staging/restore-'.bin2hex(random_bytes(3)), 0750), [], MigrationSet::image(base_path('database/migrations')));
    $runAs = RestoreOperation::runAs($this->adopt->instance->state());
    $own = AppKeys::of([new EnvFiles($this->adopt->instance->directory)->read('app')['APP_KEY']]);
    $check = static fn (PreparedBackup $backup, AppKeys $keys): Closure => static fn () => RestoreChecks::assertSameInstance($backup, $runAs['uuid'], $keys, BackupIntake::insideKeys($backup), $runAs['adopt_backup'] ?? null);
    $adopted = $json['data']['adopted']['backup_path'];
    $outside = $this->adopt->instance->directory->root.'/forged'; // goes with the instance directory
    $forged = BackupSetFixture::legacy($outside.'/a/'.basename($adopted), "APP_URL='http://localhost:8080'\n", "source=fsa adopt\napp_key_fingerprints={$own->fingerprints()[0]}\n");
    $altered = $outside.'/b/'.basename($adopted);
    mkdir(dirname($altered), 0700, true);
    exec('cp -Rp '.escapeshellarg($adopted).' '.escapeshellarg($altered));
    file_put_contents($altered.'/manifest.txt', "users=8\n", FILE_APPEND);
    file_put_contents($altered.'/SHA256SUMS', implode('', array_map(static fn (string $file): string => hash_file('sha256', $altered.'/'.$file).'  '.$file."\n", ['database.dump', 'env', 'manifest.txt', 'storage.tar.gz'])));
    $prepared = $prepare($adopted);

    expect($prepared->isLegacy())->toBeTrue()->and($prepared->problems)->toBe([])
        ->and($prepared->manifest['includes_secrets'])->toBeFalse() // review S1: its env holds no key
        ->and($check($prepared, $own))->not->toThrow(InvalidInput::class)
        ->and($check($prepared, AppKeys::of([BackupSetFixture::OTHER_KEY])))->toThrow(InvalidInput::class)
        ->and($check($prepare($forged), $own))->toThrow(InvalidInput::class) // review R1: a fingerprint is no proof
        ->and($check($prepare($altered), $own))->toThrow(InvalidInput::class);
});

it('pulls images the instance lacks before it stops 1.0', function (): void {
    $this->adopt->instance->docker->images = array_intersect_key($this->adopt->instance->docker->images, array_flip([InstanceCommandFixture::appImage()]));
    $this->adopt->instance->docker->withImage(ManifestVerifier::COSIGN_IMAGE, new ImageInfo('sha256:'.str_repeat('c', 64), []));

    [$code] = $this->adopt->adopt();
    $methods = $this->adopt->instance->docker->methods();

    expect($code)->toBe(0)->and(array_search('pullImage', $methods, true))->toBeLessThan(array_search('stopContainers', $methods, true));
});
