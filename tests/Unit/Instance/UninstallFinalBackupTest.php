<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * §13.3 "back up before destroying" (ADR 0073 as amended by the M12 gate): `fsa uninstall --keep-backups` first quiesces
 * the instance and writes backups/final-<UTC>, a 2.0 backup from the backup service's own entry point (HMAC, SHA256SUMS,
 * encrypted with the APP keys inside when the instance has recipients, else the keys beside it), and removes nothing
 * when that fails (exit 4, the stack runs again). The one-offs are scripted (RecordingDockerGateway).
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->uuid = $this->fixture->state()->uuid();
    $this->project = $this->fixture->state()->project();
    $this->fixture->withRunningStack();
    $this->fixture->docker->withResource(new DockerResource(DockerResource::VOLUME, $this->project.'_pg-data', [...Labels::forInstance($this->uuid), 'com.docker.compose.volume' => 'pg-data']))
        ->withResource(new DockerResource(DockerResource::VOLUME, $this->project.'_app-storage', [...Labels::forInstance($this->uuid), 'com.docker.compose.volume' => 'app-storage']));
    $this->backups = $this->fixture->directory->root.'/backups';
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @return list<array<string, mixed>> the backup service's one-offs */
function finalBackupRuns(InstanceCommandFixture $fixture): array
{
    return array_values(array_filter($fixture->docker->called('composeRun'), static fn (array $call): bool => $call['service'] === 'backup'));
}

it('quiesces, then writes a 2.0 final backup through the backup service before removing anything, the APP keys beside it', function (): void {
    $appKey = new EnvFiles($this->fixture->directory)->read('app')['APP_KEY'];
    $mark = count($this->fixture->docker->methods());

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);
    $run = finalBackupRuns($this->fixture)[0];
    $methods = $this->fixture->docker->methodsSince($mark);
    $stops = $this->fixture->docker->called('composeStop');
    $keyFile = $this->backups.'/final-20261019T120000Z.app-key.env';
    $warning = collect($json['warnings'])->firstWhere('code', 'app_key_beside_backup');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['final_backup'])->toBe([
            'dir' => 'backups/final-20261019T120000Z', 'backup' => 'final-20261019T120000Z', 'encrypted' => false, 'includes_secrets' => false,
            'key_file' => 'backups/final-20261019T120000Z.app-key.env', 'missing_files' => 0,
        ])
        ->and($run['command'])->toContain('--kind=final')->not->toContain('--include-secrets')
        ->and(array_column($stops, 'services'))->toBe([['app', 'scheduler', 'backup'], ['queue']])
        ->and(array_search('composeStop', $methods, true))->toBeLessThan(array_search('composeRun', $methods, true))
        ->and(array_keys($methods, 'composeRun', true)[array_key_last(array_keys($methods, 'composeRun', true))])->toBeLessThan(array_search('removeContainer', $methods, true))
        ->and($this->fixture->docker->called('composeRunToFile'))->toBe([])
        ->and(substr(sprintf('%o', fileperms($keyFile)), -3))->toBe('600')
        ->and((string) file_get_contents($keyFile))->toContain("APP_KEY='{$appKey}'")->toStartWith('# The APP keys')
        ->and($warning['message'] ?? '')->toContain($keyFile)->toContain('plaintext')
        ->and($warning['hint'] ?? '')->toContain('install.sh --dir ') // M12 gate: the uninstalled directory has no fsa any more (DOC14)
        ->and($warning['hint'] ?? '')->toContain('--from-backup '.$this->backups.'/final-20261019T120000Z --app-key-file '.$keyFile)
        ->and($json['data']['removed']['volumes'])->toBe([$this->project.'_pg-data', $this->project.'_app-storage'])
        ->and(json_encode($json))->not->toContain($appKey);
});

it('puts the APP keys and the SMTP account into the encrypted final backup when the instance has recipients', function (): void {
    $this->fixture->directory->saveState($this->fixture->state()->withRecipients(['age1'.str_repeat('a', 58)]));
    $appKey = new EnvFiles($this->fixture->directory)->read('app')['APP_KEY'];

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(finalBackupRuns($this->fixture)[0]['command'])->toContain('--kind=final')->toContain('--include-secrets')
        ->and($this->fixture->backupInput)->toContain('APP_KEY=')->toContain($appKey)
        ->and($json['data']['final_backup'])->toMatchArray(['encrypted' => true, 'includes_secrets' => true, 'key_file' => null])
        ->and(glob($this->backups.'/*.app-key.env*'))->toBe([])
        ->and(collect($json['warnings'])->firstWhere('code', 'final_backup_encrypted')['hint'] ?? '')->toContain('backup.tar.age --identity-file')->toContain('--accept-unauthenticated');
});

it('starts only Postgres for the backup when the instance is stopped, and stops nothing', function (): void {
    foreach (array_keys($this->fixture->docker->containers) as $id) {
        $this->fixture->docker->stopContainer((string) $id);
    }

    [$code] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0)
        ->and($this->fixture->docker->called('composeStop'))->toBe([])
        ->and(array_slice($this->fixture->docker->called('composeUp'), -1)[0])->toMatchArray(['services' => ['postgres'], 'waitTimeout' => 120]);
});

it('removes nothing, leaves no key file and starts the quiesced stack again when the backup fails (exit 4)', function (): void {
    $this->fixture->backupOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => 'pg_dump_failed', 'message' => 'pg_dump failed (exit 1): connection to server failed', 'hint' => null]]]);

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);
    $runtime = json_decode((string) $this->fixture->directory->read('runtime/runtime.json'), true);

    expect($code)->toBe(4, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('final_backup_failed')
        ->and($json['errors'][0]['retryable'])->toBeTrue()
        ->and($json['errors'][0]['message'])->toContain('connection to server failed')
        ->and($json['errors'][0]['hint'])->toContain('fsa uninstall --no-backup')
        ->and($json['data']['reason'] ?? null)->toBe('pg_dump_failed')
        ->and($json['changed'])->toBeFalse()
        ->and(array_slice($this->fixture->docker->called('composeUp'), -1)[0]['services'])->toEqualCanonicalizing(['app', 'scheduler', 'backup', 'queue'])
        ->and($runtime['gate']['raised'])->toBeFalse()
        ->and($this->fixture->docker->called('removeContainer'))->toBe([])
        ->and($this->fixture->docker->called('removeVolume'))->toBe([])
        ->and(glob($this->backups.'/final-*'))->toBe([])
        ->and($this->fixture->directory->hasState())->toBeTrue();
});

it('refuses when Postgres cannot be started for the backup', function (): void {
    foreach (array_keys($this->fixture->docker->containers) as $id) {
        $this->fixture->docker->stopContainer((string) $id);
    }
    $this->fixture->docker->failOn('composeUp', new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'no space left on device')));

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(4)->and($json['errors'][0]['message'])->toContain('no space left')->and($this->fixture->docker->called('removeVolume'))->toBe([]);
});

it('keeps exit 10 when Docker disappears during the final backup', function (): void {
    $this->fixture->docker->failOn('composePs', new DockerUnavailable('The Docker daemon is not reachable.'));

    [$code] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(10)->and(glob($this->backups.'/final-*'))->toBe([]);
});

it('starts what it stopped again and lowers the gate when quiescing itself fails, and removes nothing (M12 gate D2)', function (): void {
    $this->fixture->docker->failOn('composeStop', new DockerCommandFailed('compose stop', new ProcessOutcome(1, '', 'Error response from daemon: cannot stop container')));
    $mark = count($this->fixture->docker->called('composeUp'));

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);
    $runtime = json_decode((string) $this->fixture->directory->read('runtime/runtime.json'), true);
    $restarted = array_slice($this->fixture->docker->called('composeUp'), $mark);

    expect($code)->toBe(4, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('final_backup_failed')
        ->and($json['errors'][0]['message'])->toContain('compose stop')
        ->and($runtime['gate']['raised'])->toBeFalse()
        ->and(array_column($restarted, 'services'))->toHaveCount(1)
        ->and($restarted[0]['services'] ?? null)->toEqualCanonicalizing(['app', 'scheduler', 'backup', 'queue'])
        ->and(finalBackupRuns($this->fixture))->toBe([])
        ->and($this->fixture->docker->called('removeContainer'))->toBe([])
        ->and($this->fixture->docker->called('removeVolume'))->toBe([]);
});

it('lowers the gate and keeps exit 10 when Docker disappears while it quiesces (M12 gate D2)', function (): void {
    $this->fixture->docker->failOn('composeStop', new DockerUnavailable('The Docker daemon is not reachable.'));

    [$code] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);
    $runtime = json_decode((string) $this->fixture->directory->read('runtime/runtime.json'), true);

    expect($code)->toBe(10)
        ->and($runtime['gate']['raised'])->toBeFalse()
        ->and(glob($this->backups.'/final-*'))->toBe([]);
});

it('cannot back up without a readable state and says how to go on', function (): void {
    $this->fixture->directory->write('state.json', '{"broken": true}');

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['message'])->toContain('state is unreadable')
        ->and($json['errors'][0]['hint'])->toContain('--no-backup');
});

it('refuses without the backup role\'s password, and removes nothing', function (): void {
    new EnvFiles($this->fixture->directory)->write('backup', ['DB_HOST' => 'postgres']);

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('final_backup_failed')
        ->and($json['errors'][0]['message'])->toContain('no password for the backup role')
        ->and($this->fixture->docker->called('removeVolume'))->toBe([]);
});

it('saves only the APP keys when no database volume is left (to the recipients when encryption is on), and nothing without a key', function (): void {
    $this->fixture->docker->resources = [];
    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    $bare = new InstanceCommandFixture;
    [$bareCode, $nothing] = $bare->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['final_backup'])->toMatchArray(['dir' => null, 'backup' => null, 'encrypted' => false])
        ->and($json['data']['final_backup']['key_file'])->toMatch('#^backups/final-\d{8}T\d{6}Z\.app-key\.env$#')
        ->and(is_file($this->fixture->directory->root.'/'.$json['data']['final_backup']['key_file']))->toBeTrue()
        ->and(finalBackupRuns($this->fixture))->toBe([])
        ->and($bareCode)->toBe(0)
        ->and($nothing['data']['final_backup'])->toBeNull();
});

it('encrypts the saved APP keys to the recipients when there is no database to back up and encryption is on', function (): void {
    app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
    $recipient = 'age1'.str_repeat('a', 58);
    $this->fixture->directory->saveState($this->fixture->state()->withRecipients([$recipient]));
    $this->fixture->docker->resources = [];

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);
    $file = $this->fixture->directory->root.'/'.$json['data']['final_backup']['key_file'];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['final_backup']['key_file'])->toEndWith('.app-key.env.age')
        ->and((string) file_get_contents($file))->toStartWith('fake-age:to:'.$recipient)
        ->and(substr(sprintf('%o', fileperms($file)), -3))->toBe('600')
        ->and(glob($this->backups.'/*.app-key.env'))->toBe([])
        ->and(collect($json['warnings'])->firstWhere('code', 'app_key_beside_backup')['message'] ?? '')->toContain('encrypted to the backup recipients');
});
