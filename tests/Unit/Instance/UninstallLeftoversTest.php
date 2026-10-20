<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fsa uninstall` after a backup run that was cut off (M12 gate): its partial sets in backups/ and staging/backup/ stay
 * root's (0700, gate S1), which the ops container cannot remove when it runs as the host user (rootful Docker). The
 * backup service removes them as root under the uninstall lock before the stack goes (`backup-daemon --clean`), and
 * what still stays is named instead of reported deleted. A directory with mode 0 stands in for root's (the tests do
 * not run as root).
 */

const PARTIAL = 'backups/scheduled-20261019T033000Z.partial';

const STAGED = 'staging/backup/manual-20261019T120000Z.partial';

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->uuid = $this->fixture->state()->uuid();
    $this->fixture->withRunningStack();
    $this->fixture->docker->withResource(new DockerResource(DockerResource::VOLUME, 'proj_pg-data', Labels::forInstance($this->uuid)));
    $this->root = $this->fixture->directory->root;
});

afterEach(function (): void {
    exec('chmod -R u+rwx '.escapeshellarg($this->root).' 2>/dev/null');
    TemporaryInstance::cleanup();
});

/** Partial sets as an interrupted run leaves them: not readable for anyone but their owner (root in production). */
function plantPartials(string $root): void
{
    foreach ([PARTIAL, STAGED] as $partial) {
        @mkdir($root.'/'.$partial, 0700, true);
        file_put_contents($root.'/'.$partial.'/database.dump', 'PGDMP plaintext');
        chmod($root.'/'.$partial, 0);
    }
}

/** @return list<array<string, mixed>> the backup service's --clean one-offs */
function cleanRuns(InstanceCommandFixture $fixture): array
{
    return array_values(array_filter($fixture->docker->called('composeRun'), static fn (array $call): bool => $call['service'] === 'backup' && in_array('--clean', $call['command'], true)));
}

it('has the backup service remove partial sets as root under the uninstall lock while the stack still exists (M12 gate)', function (): void {
    plantPartials($this->root);
    $this->fixture->onClean = function (): void { // what the service's root can do
        foreach ([PARTIAL, STAGED] as $partial) {
            chmod($this->root.'/'.$partial, 0700);
            exec('rm -rf '.escapeshellarg($this->root.'/'.$partial));
        }
    };

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);
    $methods = $this->fixture->docker->methods();
    $clean = cleanRuns($this->fixture);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($clean)->toHaveCount(1)
        ->and(implode(' ', $clean[0]['command']))->toMatch('/^fsa backup-daemon --clean --lock-id=[0-9a-f]{32} --json$/')
        ->and(array_search('composeRun', $methods, true))->toBeLessThan(array_search('removeContainer', $methods, true))
        ->and($json['data'])->toMatchArray(['data_deleted' => true, 'backups_deleted' => true, 'left_files' => []])
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('files_left')
        ->and(scandir($this->root))->toBe(['.', '..']);
})->skip(fn (): bool => posix_geteuid() === 0, 'root removes any file');

it('names the files it could not remove instead of reporting data and backups deleted (M12 gate)', function (): void {
    plantPartials($this->root);
    $this->fixture->cleanOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => 'internal_error', 'message' => 'no such image', 'hint' => null]]]);

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);
    $warning = collect($json['warnings'])->firstWhere('code', 'files_left');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['data_deleted' => false, 'backups_deleted' => false, 'left_files' => [PARTIAL, STAGED]])
        ->and($json['data']['removed']['volumes'])->toBe(['proj_pg-data'])
        ->and($warning['message'] ?? '')->toContain($this->root.'/'.PARTIAL)->toContain($this->root.'/'.STAGED)
        ->and($warning['hint'] ?? '')->toContain('sudo rm -rf')
        ->and(is_dir($this->root.'/'.STAGED))->toBeTrue();
})->skip(fn (): bool => posix_geteuid() === 0, 'root removes any file');

it('keeps backups/ with --keep-backups and reports only the data left outside it', function (): void {
    plantPartials($this->root);
    $this->fixture->cleanOutcome = InstanceCommandFixture::oneOffJson(['command' => 'backup-daemon', 'status' => 'failed', 'errors' => [['code' => 'lock_mismatch', 'message' => 'lost', 'hint' => null]]]);

    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true, '--keep-backups' => true, '--no-backup' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['data_deleted' => false, 'backups_deleted' => false, 'kept' => ['backups'], 'left_files' => [STAGED]])
        ->and(is_dir($this->root.'/backups'))->toBeTrue();
})->skip(fn (): bool => posix_geteuid() === 0, 'root removes any file');

it('runs no clean-up one-off when no interrupted run left anything', function (): void {
    [$code, $json] = $this->fixture->run('fsa:uninstall', ['--yes' => true]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(cleanRuns($this->fixture))->toBe([])
        ->and($json['data'])->toMatchArray(['data_deleted' => true, 'backups_deleted' => true, 'left_files' => []]);
});
