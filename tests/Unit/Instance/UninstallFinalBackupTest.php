<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M9 gate fix (§13.3 "back up before destroying", ADR 0073): `fsa uninstall --keep-backups` first writes
 * backups/final-<UTC>/ (database dump and storage archive from socket-less one-offs, APP_KEY, manifest) and
 * removes nothing when that fails (exit 4). The real pg_dump and tar run against Docker in the J01 journey only
 * through `fsa uninstall` without --keep-backups; here the one-offs are scripted (RecordingDockerGateway).
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

/** @return list<string> the final-* directories under backups/ */
function finalBackups(string $backups): array
{
    return array_values(array_map(basename(...), glob($backups.'/final-*') ?: []));
}

it('writes a final backup before removing anything: dump, storage archive, APP_KEY and manifest, all private', function (): void {
    $appKey = new EnvFiles($this->fixture->directory)->read('app')['APP_KEY'];

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);
    [$dir] = finalBackups($this->backups);
    $path = $this->backups.'/'.$dir;
    [$dump, $tar] = $this->fixture->docker->called('composeRunToFile');
    $methods = $this->fixture->docker->methods();
    $manifest = json_decode((string) file_get_contents($path.'/backup.json'), true);

    expect($code)->toBe(0, json_encode($json))
        ->and($dir)->toMatch('/^final-\d{8}T\d{6}Z$/')
        ->and($json['data']['final_backup'])->toBe(['dir' => 'backups/'.$dir, 'files' => ['app-key.env', 'database.dump', 'app-storage.tar', 'backup.json']])
        ->and($json['data']['removed']['volumes'])->toBe([$this->project.'_pg-data', $this->project.'_app-storage'])
        ->and(substr(sprintf('%o', fileperms($path)), -3))->toBe('700')
        ->and(array_map(static fn (string $f): string => substr(sprintf('%o', fileperms($path.'/'.$f)), -3), ['app-key.env', 'database.dump', 'app-storage.tar', 'backup.json']))->toBe(['600', '600', '600', '600'])
        ->and((string) file_get_contents($path.'/database.dump'))->toStartWith('PGDMP')
        ->and((string) file_get_contents($path.'/app-key.env'))->toContain("APP_KEY='{$appKey}'")->toStartWith('# The key that decrypts')
        ->and($dump)->toMatchArray(['service' => 'app', 'entrypoint' => 'pg_dump', 'command' => ['--format=custom', '--no-password'], 'file' => $path.'/database.dump', 'env' => ['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD']])
        ->and($tar)->toMatchArray(['service' => 'app', 'entrypoint' => 'tar', 'command' => ['-C', '/var/www/html/storage/app', '-cf', '-', '.'], 'file' => $path.'/app-storage.tar'])
        ->and(array_search('composeRunToFile', $methods, true))->toBeLessThan(array_search('removeContainer', $methods, true))
        ->and($manifest)->toMatchArray(['kind' => 'final', 'uuid' => $this->uuid, 'version' => '2.0.0-alpha.1'])
        ->and($manifest['files']['database.dump']['sha256'])->toBe(hash_file('sha256', $path.'/database.dump'))
        ->and(json_encode($json))->not->toContain($appKey);
});

it('starts only Postgres for the dump when the instance is stopped', function (): void {
    foreach (array_keys($this->fixture->docker->containers) as $id) {
        $this->fixture->docker->stopContainer((string) $id);
    }

    [$code] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0)
        ->and(array_slice($this->fixture->docker->called('composeUp'), -1)[0])->toMatchArray(['services' => ['postgres'], 'waitTimeout' => 120]);
});

it('removes nothing and leaves no partial backup when the dump fails (exit 4)', function (string $failure): void {
    $this->fixture->docker->fileRunResponder = static fn (?string $entrypoint, array $command, string $file): ?ProcessOutcome => match (true) {
        $failure === 'exit' && $entrypoint === 'pg_dump' => new ProcessOutcome(1, '', 'pg_dump: error: connection to server failed'),
        $failure === 'garbage' && $entrypoint === 'tar' => (file_put_contents($file, 'PHP Warning: not a tar') !== false ? new ProcessOutcome(0) : null),
        default => null,
    };

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['code'])->toBe('final_backup_failed')
        ->and($json['errors'][0]['retryable'])->toBeTrue()
        ->and($json['errors'][0]['message'])->toContain($failure === 'exit' ? 'connection to server failed' : 'tar failed')
        ->and($json['changed'])->toBeFalse()
        ->and($this->fixture->docker->called('removeContainer'))->toBe([])
        ->and($this->fixture->docker->called('removeVolume'))->toBe([])
        ->and(finalBackups($this->backups))->toBe([])
        ->and($this->fixture->directory->hasState())->toBeTrue();
})->with(['exit', 'garbage']);

it('refuses when Postgres cannot be started for the dump', function (): void {
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

    expect($code)->toBe(10)->and(finalBackups($this->backups))->toBe([]);
});

it('cannot dump without a readable state and says how to go on', function (): void {
    $this->fixture->directory->write('state.json', '{"broken": true}');

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['message'])->toContain('state is unreadable')
        ->and($json['errors'][0]['hint'])->toContain('without --keep-backups');
});

it('refuses to dump without the database password in env/app.env', function (): void {
    new EnvFiles($this->fixture->directory)->write('app', ['DB_HOST' => 'postgres']);

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(4)
        ->and($json['errors'][0]['message'])->toContain('holds no database password')
        ->and(finalBackups($this->backups))->toBe([]);
});

it('saves only the APP_KEY when no volume is left, and nothing at all without one', function (): void {
    $this->fixture->docker->resources = [];
    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    $bare = new InstanceCommandFixture;
    [$bareCode, $nothing] = $bare->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true]);

    expect($code)->toBe(0)
        ->and($json['data']['final_backup']['files'])->toBe(['app-key.env', 'backup.json'])
        ->and($this->fixture->docker->called('composeRunToFile'))->toBe([])
        ->and($bareCode)->toBe(0)
        ->and($nothing['data']['final_backup'])->toBeNull();
});
