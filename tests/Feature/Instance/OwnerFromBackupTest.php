<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\Docker\WslBackend;
use Tests\Support\Instance\FromBackupFixture;

/*
 * 2.1.1 U3 (R29, KTD3): a backup carries no file owner, so `fieldset install --from-backup` restores as the owner the
 * target host's shim measured. The same backup (e.g. made on macOS, where the owner is container root) lands on WSL 2
 * with the host user's files, and on macOS with container root's.
 */

beforeEach(function (): void {
    $this->move = new FromBackupFixture;
});

afterEach(function (): void {
    $this->move->cleanup();
});

it('installs a backup as the target host\'s measured owner: the host user on WSL 2, container root on macOS (R29)', function (bool $wsl): void {
    $uid = posix_getuid();
    $gid = posix_getgid();
    $this->move->instance->facts([
        'lanAddresses' => [FromBackupFixture::HOST_ADDRESS], 'runtime' => DockerRuntime::Desktop, 'ownerSource' => OwnerSource::Measured,
        'containerUid' => $wsl ? $uid : 0, 'socketGroup' => $wsl ? 0 : null, 'wslVersion' => $wsl ? 2 : 0, 'wslBackend' => $wsl ? WslBackend::DockerDesktop : null,
        'os' => $wsl ? 'linux' : 'darwin',
    ]);
    $root = $this->move->instance->directory->root;
    foreach (['', '/state.json', '/compose.yaml', '/fieldset'] as $file) { // as the ops container sees them: on macOS container root reads 0
        $this->move->instance->probes->stats[$root.$file] = ['uid' => $wsl ? $uid : 0, 'mode' => 0600];
    }

    [$code, $json] = $this->move->install($this->move->backup(), ['--app-key-file' => $this->move->keyFile()]);
    $state = $this->move->instance->state();
    $owner = $wsl ? "{$uid}:{$gid}" : '0:0';

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($state->containerUid())->toBe($wsl ? $uid : 0)
        ->and([$state->measuredRuntime(), $state->socketGroup()])->toBe([DockerRuntime::Desktop, $wsl ? 0 : null])
        ->and($this->move->specs('restore-prepare')[0]->user)->toBe($owner)
        ->and($this->move->specs('tar')[0]->groupAdd)->toBe([$wsl ? (string) $gid : '0'])
        ->and($this->move->specs('pg_restore')[0]->user)->toBe($owner);
})->with(['WSL 2 with Docker Desktop' => true, 'macOS' => false]);
