<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FieldsetCommand;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Lifecycle\LauncherScript;
use App\Domain\Instance\Lifecycle\ScriptRefresh;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\State\InstanceDirectory;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

/*
 * 2.1.1 U15: newer images refresh an older `fieldset` script (KTD7, R38). After a command the shim started, a launcher
 * whose version (FIELDSET_LAUNCHER_VERSION; none before 2.1.1) is lower than the image's distribution/shim.sh gets that
 * script: under the instance lock (never waited for), temp file plus rename, 0700 and the old owner, one progress line.
 * Same or newer launcher: no change. Any failure keeps the old script and warns.
 */

const OLD_SCRIPT = "#!/bin/sh\n# fieldset 2.1.0: passes no launcher version\n";

beforeEach(function (): void {
    $this->directory = TemporaryInstance::withState();
    $this->docker = new RecordingDockerGateway;
    $this->script = $this->directory->root.'/fieldset';
    file_put_contents($this->script, OLD_SCRIPT);
    chmod($this->script, 0700);
    $this->bundled = (string) file_get_contents(base_path('distribution/shim.sh'));
    $this->version = LauncherScript::versionOf($this->bundled);
    $this->output = new BufferedOutput;
});

afterEach(fn () => TemporaryInstance::cleanup());

/** Host facts of a command the shim started (it wrote the nonce); $version null: a launcher before 2.1.1. */
function refreshFacts(InstanceDirectory $directory, ?int $version, array $overrides = []): HostFacts
{
    $nonce = '.fieldset-nonce-'.str_repeat('a', 32);
    touch($directory->root.'/'.$nonce);

    return new HostFacts(...['uid' => posix_getuid(), 'gid' => posix_getgid(), 'instanceDir' => $directory->root, 'launcher' => 'shim',
        'nonce' => $nonce, 'launcherVersion' => $version, ...$overrides]);
}

function refresher(InstanceDirectory $directory, RecordingDockerGateway $docker, ?string $bundled = null, ?int $euid = null): ScriptRefresh
{
    return new ScriptRefresh($directory, new InstanceLock($directory, $docker), new ContainerIdentity($docker, '/nonexistent/mountinfo', 'ops-host'), $bundled, $euid);
}

function refreshProgress(BufferedOutput $output, bool $json = false): ProgressReporter
{
    return new ProgressReporter($output, $json, new SecretRedactor);
}

/** A copy of the image's shim that declares another version (a newer or older release's script). */
function shimWithVersion(string $shim, int $version): string
{
    return (string) preg_replace('/^fieldset_shim_version=\d+$/m', 'fieldset_shim_version='.$version, $shim);
}

it('declares a version in the image\'s shim', function (): void {
    expect($this->version)->toBeGreaterThanOrEqual(1)
        ->and(LauncherScript::versionOf(OLD_SCRIPT))->toBe(0)
        ->and(LauncherScript::bundled()?->version)->toBe($this->version)
        ->and(LauncherScript::bundled()?->contents)->toBe($this->bundled);
});

it('reads the launcher version of 2.1.1 shims; none or out of grammar is null (older than any)', function (array $env, ?int $version): void {
    expect(HostFacts::fromEnvironment($env)->launcherVersion)->toBe($version);
})->with([
    'a 2.1.1 shim' => [['FIELDSET_LAUNCHER_VERSION' => '1'], 1],
    'a later shim' => [['FIELDSET_LAUNCHER_VERSION' => '12'], 12],
    'a shim before 2.1.1' => [[], null],
    'out of grammar' => [['FIELDSET_LAUNCHER_VERSION' => '1; rm -rf /'], null],
    'negative' => [['FIELDSET_LAUNCHER_VERSION' => '-1'], null],
]);

it('replaces a script without a version with the image\'s: owner and 0700 kept, one line saying the next command uses it', function (bool $json): void {
    $owner = fileowner($this->script);
    $inode = fileinode($this->script);

    $replaced = refresher($this->directory, $this->docker)->afterCommand(refreshFacts($this->directory, null), refreshProgress($this->output, $json));
    clearstatcache();
    $lines = array_values(array_filter(explode("\n", $this->output->fetch())));

    expect($replaced)->toBeTrue()
        ->and(file_get_contents($this->script))->toBe($this->bundled)
        ->and(fileperms($this->script) & 0777)->toBe(0700)
        ->and(fileowner($this->script))->toBe($owner)
        ->and(fileinode($this->script))->not->toBe($inode) // renamed over: the running shim keeps reading its own copy
        ->and(glob($this->directory->root.'/.fieldset.tmp-*'))->toBe([])
        ->and($this->directory->exists(InstanceLock::DIRECTORY))->toBeFalse()
        ->and($lines)->toHaveCount(1);
    if ($json) {
        expect(json_decode($lines[0], true))->toMatchArray(['phase' => 'launcher', 'level' => 'info'])
            ->and(json_decode($lines[0], true)['message'])->toContain($this->script, 'the next command uses it');
    } else {
        expect($lines[0])->toStartWith('› ')->toContain($this->script, 'the next command uses it');
    }
})->with(['human' => false, 'json' => true]);

it('replaces an older versioned script, as from an earlier 2.1.x', function (): void {
    file_put_contents($this->script, shimWithVersion($this->bundled, $this->version - 1));
    $bundled = $this->directory->root.'-image-shim.sh';
    file_put_contents($bundled, shimWithVersion($this->bundled, $this->version + 1));

    try {
        $replaced = refresher($this->directory, $this->docker, $bundled)->afterCommand(refreshFacts($this->directory, $this->version - 1), refreshProgress($this->output));

        expect($replaced)->toBeTrue()
            ->and(LauncherScript::versionOf((string) file_get_contents($this->script)))->toBe($this->version + 1);
    } finally {
        unlink($bundled);
    }
});

it('changes nothing when the launcher has the image\'s version', function (): void {
    $inode = fileinode($this->script);

    $replaced = refresher($this->directory, $this->docker)->afterCommand(refreshFacts($this->directory, $this->version), refreshProgress($this->output));

    expect($replaced)->toBeFalse()
        ->and(file_get_contents($this->script))->toBe(OLD_SCRIPT)
        ->and(fileinode($this->script))->toBe($inode)
        ->and($this->output->fetch())->toBe('')
        ->and($this->docker->calls)->toBe([]);
});

it('changes nothing when the launcher is newer than the image (after a rollback)', function (): void {
    $newer = shimWithVersion($this->bundled, $this->version + 1);
    file_put_contents($this->script, $newer);

    $replaced = refresher($this->directory, $this->docker)->afterCommand(refreshFacts($this->directory, $this->version + 1), refreshProgress($this->output));

    expect($replaced)->toBeFalse()
        ->and(file_get_contents($this->script))->toBe($newer)
        ->and($this->output->fetch())->toBe('');
});

it('changes nothing when the script on disk is already the image\'s or newer (another command refreshed it)', function (int $onDisk): void {
    $script = shimWithVersion($this->bundled, $this->version + $onDisk);
    file_put_contents($this->script, $script);

    $replaced = refresher($this->directory, $this->docker)->afterCommand(refreshFacts($this->directory, null), refreshProgress($this->output));

    expect($replaced)->toBeFalse()
        ->and(file_get_contents($this->script))->toBe($script)
        ->and($this->output->fetch())->toBe('');
})->with(['the image\'s' => 0, 'newer' => 1]);

it('keeps the old script and warns when the instance directory is not writable', function (): void {
    $this->directory->ensureDirectory(InstanceDirectory::LOCKS_DIR);
    $facts = refreshFacts($this->directory, null);
    chmod($this->directory->root, 0500);

    try {
        $replaced = refresher($this->directory, $this->docker)->afterCommand($facts, refreshProgress($this->output));
    } finally {
        chmod($this->directory->root, 0700);
    }
    $output = $this->output->fetch();

    expect($replaced)->toBeFalse()
        ->and(file_get_contents($this->script))->toBe(OLD_SCRIPT)
        ->and(fileperms($this->script) & 0777)->toBe(0700)
        ->and(glob($this->directory->root.'/.fieldset.tmp-*'))->toBe([])
        ->and($this->directory->exists(InstanceLock::DIRECTORY))->toBeFalse()
        ->and($output)->toStartWith('! ')->toContain('Kept the old fieldset script', $this->script, 'A later command tries again')
        ->and(substr_count($output, "\n"))->toBe(1);
})->skip(fn (): bool => posix_geteuid() === 0, 'root writes into any directory');

it('keeps the script of another user and warns (the ops container runs as someone else)', function (): void {
    $replaced = refresher($this->directory, $this->docker, euid: posix_geteuid() + 1)->afterCommand(refreshFacts($this->directory, null), refreshProgress($this->output));

    expect($replaced)->toBeFalse()
        ->and(file_get_contents($this->script))->toBe(OLD_SCRIPT)
        ->and($this->output->fetch())->toContain('Kept the old fieldset script', 'belongs to UID '.fileowner($this->script));
});

it('refuses a symlinked script and leaves its target alone', function (): void {
    $target = $this->directory->root.'-elsewhere';
    file_put_contents($target, OLD_SCRIPT);
    unlink($this->script);
    symlink($target, $this->script);

    try {
        $replaced = refresher($this->directory, $this->docker)->afterCommand(refreshFacts($this->directory, null), refreshProgress($this->output));

        expect($replaced)->toBeFalse()
            ->and(is_link($this->script))->toBeTrue()
            ->and(file_get_contents($target))->toBe(OLD_SCRIPT)
            ->and($this->output->fetch())->toContain('Kept the old fieldset script', 'not a regular file');
    } finally {
        unlink($target);
    }
});

it('skips while another operation holds the instance lock: a later command refreshes', function (): void {
    $holder = TemporaryInstance::holder('b');
    $this->docker->withRunningContainer($holder->containerId, '2026-10-17T10:00:00.000000001Z');
    $lock = new InstanceLock($this->directory, $this->docker);
    $handle = $lock->acquire($holder, 'upgrade');

    $replaced = refresher($this->directory, $this->docker)->afterCommand(refreshFacts($this->directory, null), refreshProgress($this->output));

    expect($replaced)->toBeFalse()
        ->and(file_get_contents($this->script))->toBe(OLD_SCRIPT)
        ->and($this->output->fetch())->toBe('')
        ->and($lock->current()?->lockId)->toBe($handle->record->lockId);
});

it('does nothing for install.sh, a command the shim did not start, a directory without its marker or without a script', function (Closure $arrange): void {
    $facts = $arrange($this);

    $replaced = refresher($this->directory, $this->docker)->afterCommand($facts, refreshProgress($this->output));

    expect($replaced)->toBeFalse()
        ->and($this->output->fetch())->toBe('')
        ->and(is_file($this->script) ? file_get_contents($this->script) : null)->toBeIn([OLD_SCRIPT, null])
        ->and($this->docker->calls)->toBe([]);
})->with([
    'install.sh (it writes the script itself)' => [fn ($test): HostFacts => refreshFacts($test->directory, null, ['launcher' => 'install.sh'])],
    'no launcher (development)' => [fn ($test): HostFacts => refreshFacts($test->directory, null, ['launcher' => null])],
    'no nonce' => [fn ($test): HostFacts => refreshFacts($test->directory, null, ['nonce' => null])],
    'no marker (uninstalled)' => [function ($test): HostFacts {
        unlink($test->directory->root.'/'.InstanceDirectory::MARKER);

        return refreshFacts($test->directory, null);
    }],
    'no script (install writes it)' => [function ($test): HostFacts {
        unlink($test->script);

        return refreshFacts($test->directory, null);
    }],
]);

it('refreshes after any command the shim started, a failed one too, with --json still one document on stdout', function (): void {
    $fixture = new InstanceCommandFixture;
    file_put_contents($fixture->directory->root.'/fieldset', OLD_SCRIPT);
    chmod($fixture->directory->root.'/fieldset', 0700);
    $facts = refreshFacts($fixture->directory, null);
    $fixture->facts(['nonce' => $facts->nonce, 'launcherVersion' => null]);
    app()->instance(FieldsetCommand::PROGRESS_OUTPUT, $this->output);

    $code = Artisan::call('fieldset:logs', ['--json' => true, '--dir' => $fixture->directory->root]);
    $stdout = trim(Artisan::output());
    $events = array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", $this->output->fetch()))));

    expect($code)->not->toBe(0) // no state yet: the command fails, the refresh still runs
        ->and(json_decode($stdout, true)['command'] ?? null)->toBe('logs')
        ->and(file_get_contents($fixture->directory->root.'/fieldset'))->toBe($this->bundled)
        ->and(end($events))->toMatchArray(['phase' => 'launcher', 'level' => 'info']);
});

it('leaves the script to uninstall, which removes the instance directory', function (): void {
    $fixture = new InstanceCommandFixture;
    file_put_contents($fixture->directory->root.'/fieldset', OLD_SCRIPT);
    $facts = refreshFacts($fixture->directory, null);
    $fixture->facts(['nonce' => $facts->nonce, 'launcherVersion' => null]);
    app()->instance(FieldsetCommand::PROGRESS_OUTPUT, $this->output);

    [$code] = $fixture->run('fieldset:uninstall'); // without --yes: exit 6, after the context was built

    expect($code)->toBe(6)
        ->and(file_get_contents($fixture->directory->root.'/fieldset'))->toBe(OLD_SCRIPT)
        ->and($this->output->fetch())->not->toContain('launcher');
});
