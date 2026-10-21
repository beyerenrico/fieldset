<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FilePathFlags;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Docker\DistributionScripts;
use Tests\Support\Instance\FsaCommandFlags;

/*
 * U4 / M9-T07: distribution/shim.sh, installed as <instance dir>/fsa (§6.2, KTD2, ADR 0049, ADR 0070): the ops
 * image from the state, host facts, same-path mounts for the instance and every file-path flag, the nonce, the
 * user per Docker runtime and the browser hand-over. Docker is a recording stub here; the journey runs it for real.
 */

const SHIM_IMAGE = 'ghcr.io/beyerenrico/form-schema-app@sha256:1111111111111111111111111111111111111111111111111111111111111111';

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    mkdir($this->scripts->root.'/home');
    $this->dir = $this->scripts->root.'/instance';
    mkdir($this->dir, 0700);
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $this->dir, 501, 20, 501)
        ->withRelease('2.0.0', ['app' => SHIM_IMAGE, 'postgres' => 'docker.io/library/postgres@sha256:'.str_repeat('2', 64)])
        ->withPreviousRelease('2.0.0-rc.1', ['app' => 'ghcr.io/beyerenrico/form-schema-app@sha256:'.str_repeat('3', 64)], '{"services":{}}');
    file_put_contents($this->dir.'/state.json', $state->toJson());
    copy(base_path('distribution/shim.sh'), $this->dir.'/fsa');
    chmod($this->dir.'/fsa', 0700);
    $this->out = $this->scripts->root.'/out';
    mkdir($this->out);
    $this->env = ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]'];
});

afterEach(fn () => $this->scripts->cleanUp());

/** @param  list<string>  $run  @return list<string> the `-v` values of a docker run */
function shimVolumes(array $run): array
{
    $volumes = [];
    foreach ($run as $index => $arg) {
        if ($arg === '-v') {
            $volumes[] = $run[$index + 1];
        }
    }

    return $volumes;
}

it('runs the command in the ops image recorded in the state, never the previous one', function (): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status', '--json'], $this->env);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_slice($run, array_search('--entrypoint', $run, true)))->toBe(['--entrypoint', 'fsa', SHIM_IMAGE, 'status', '--dir', $this->dir, '--json'])
        ->and($run[1])->toBe('-i')
        ->and($run)->not->toContain('-it')
        ->and($this->scripts->opsEnvironment())->toMatchArray(['FIELDSET_LAUNCHER' => 'shim', 'FIELDSET_TTY' => '0', 'FIELDSET_INSTANCE_DIR' => $this->dir]);
});

it('runs the ops container as DOCKER_DEFAULT_PLATFORM and passes it on, so fsa\'s docker calls use it too (ADR 0070)', function (): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status', '--json'], [...$this->env, 'DOCKER_DEFAULT_PLATFORM' => 'linux/amd64']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(implode(' ', $this->scripts->opsRuns()[0]))->toContain('-e DOCKER_DEFAULT_PLATFORM ')
        ->and($this->scripts->opsEnvironment())->toMatchArray(['DOCKER_DEFAULT_PLATFORM' => 'linux/amd64', 'FIELDSET_LAUNCHER' => 'shim'])
        ->and($this->scripts->dockerPlatforms())->toBe([['info', 'linux/amd64'], ['run', 'linux/amd64']]);
});

it('refuses an invalid DOCKER_DEFAULT_PLATFORM with exit 2 before calling Docker, and passes none when unset', function (): void {
    $bad = $this->scripts->run($this->dir.'/fsa', ['status', '--json'], [...$this->env, 'DOCKER_DEFAULT_PLATFORM' => 'linux/riscv64']);
    $json = json_decode($bad->getOutput(), true);
    $calls = $this->scripts->calls('docker');
    $unset = $this->scripts->run($this->dir.'/fsa', ['status'], $this->env);

    expect($bad->getExitCode())->toBe(2)
        ->and($json['command'])->toBe('status')
        ->and($json['errors'][0]['code'])->toBe('invalid_platform')
        ->and($json['errors'][0]['message'])->toContain('not linux/riscv64')
        ->and($calls)->toBe([])
        ->and(glob($this->dir.'/.fsa-nonce-*'))->toBe([])
        ->and($unset->getExitCode())->toBe(0, $unset->getErrorOutput())
        ->and($this->scripts->opsEnvironment())->not->toHaveKey('DOCKER_DEFAULT_PLATFORM');
});

it('keeps HOME and DOCKER_CONFIG on a container-private tmpfs owned by the container user, never /tmp', function (array $env, string $owner): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status'], [...$this->env, ...$env]);
    $run = $this->scripts->opsRuns()[0];
    $owner = str_replace(['{uid}', '{gid}'], [trim((string) shell_exec('id -u')), trim((string) shell_exec('id -g'))], $owner);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($run[array_search('--tmpfs', $run, true) + 1])->toBe("/run/fsa-home:mode=0700,{$owner}")
        ->and($run)->toContain('HOME=/run/fsa-home', 'DOCKER_CONFIG=/run/fsa-home/.docker')
        ->and(implode(' ', $run))->not->toContain('/tmp');
})->with([
    'rootful: the host user' => [[], 'uid={uid},gid={gid}'],
    'Docker Desktop: root' => [['STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'], 'uid=0,gid=0'],
]);

it('takes the first argument that is no flag as the command and puts --dir <instance> right after it', function (array $arguments, array $expected, string $command): void {
    $process = $this->scripts->run($this->dir.'/fsa', $arguments, [...$this->env, 'STUB_OPS_EXIT' => '125']);
    $run = $this->scripts->opsRuns()[0];
    $json = json_decode($process->getOutput(), true);

    expect(array_slice($run, array_search(SHIM_IMAGE, $run, true) + 1))->toBe(str_replace('{dir}', $this->dir, $expected))
        ->and($json['command'] ?? null)->toBe($command);
})->with([
    'flag before the command' => [['--json', 'status'], ['status', '--dir', '{dir}', '--json'], 'status'],
    'flags around the command' => [['--yes', 'uninstall', '--json'], ['uninstall', '--dir', '{dir}', '--yes', '--json'], 'uninstall'],
    'literal -- before a positional (fsa login-link -- owner@…)' => [['--json', 'login-link', '--', 'owner@example.com'], ['login-link', '--dir', '{dir}', '--json', '--', 'owner@example.com'], 'login-link'],
    'file flag after -- stays literal' => [['--json', 'login-link', '--', '--secrets-file'], ['login-link', '--dir', '{dir}', '--json', '--', '--secrets-file'], 'login-link'],
    'value flag before the command keeps its value' => [['--behind-proxy', 'traefik', '--json', 'install'], ['install', '--dir', '{dir}', '--behind-proxy', 'traefik', '--json'], 'install'],
    'optional-value flags before the command' => [['--port', '8080', '--org', 'Acme', '--json', 'install', '--yes'], ['install', '--dir', '{dir}', '--port', '8080', '--org', 'Acme', '--json', '--yes'], 'install'],
    'flag=value before the command' => [['--domain=forms.example', '--json', 'install'], ['install', '--dir', '{dir}', '--domain=forms.example', '--json'], 'install'],
    'value flag after the command' => [['--json', 'logs', '--tail', '50', 'app'], ['logs', '--dir', '{dir}', '--json', '--tail', '50', 'app'], 'logs'],
]);

it('knows every value-taking option of the fsa commands, so a flag before the command keeps its value', function (): void {
    preg_match("/^fsa_value_flags='([^']+)'$/m", (string) file_get_contents(base_path('distribution/shim.sh')), $listed);

    expect(preg_split('/\s+/', trim($listed[1] ?? '')))->toBe(FsaCommandFlags::valueFlags());
});

it('lists the commands without a command, and adds --dir only to a command', function (): void {
    $this->scripts->run($this->dir.'/fsa', [], $this->env);
    $this->scripts->run($this->dir.'/fsa', ['--help'], $this->env);
    [$list, $help] = $this->scripts->opsRuns();

    expect(array_slice($list, array_search(SHIM_IMAGE, $list, true)))->toBe([SHIM_IMAGE])
        ->and(array_slice($help, array_search(SHIM_IMAGE, $help, true)))->toBe([SHIM_IMAGE, '--help']);
});

it('fails with exit 3 when the state is missing or names no valid image', function (string $state): void {
    $state === '' ? unlink($this->dir.'/state.json') : file_put_contents($this->dir.'/state.json', $state);

    $process = $this->scripts->run($this->dir.'/fsa', ['doctor', '--json'], $this->env);
    $json = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(3)
        ->and($json['command'])->toBe('doctor')
        ->and($json['errors'][0]['code'])->toBe('no_state')
        ->and($json['errors'][0]['hint'])->toContain('install.sh')
        ->and($this->scripts->opsRuns())->toBe([]);
})->with([
    'missing' => '',
    'tag instead of digest' => "{\n    \"digests\": {\n        \"app\": \"ghcr.io/beyerenrico/form-schema-app:latest\"\n    }\n}\n",
    'injection attempt' => "{\n    \"digests\": {\n        \"app\": \"x@sha256:".str_repeat('a', 64)." --privileged\"\n    }\n}\n",
    'empty digests' => "{\n    \"digests\": [],\n    \"previous\": {\n        \"digests\": {\n            \"app\": \"".SHIM_IMAGE."\"\n        }\n    }\n}\n",
]);

it('mounts the parent of every file-path flag at the same path, read-only for inputs (KTD2)', function (): void {
    mkdir($this->scripts->root.'/keys');
    file_put_contents($this->scripts->root.'/keys/pw', 'secret');
    $hook = $this->scripts->writeExecutable('ops-hook', "#!/bin/sh\n[ -f \"\$FIELDSET_INSTANCE_DIR/\$FIELDSET_NONCE\" ] && [ -f \"{$this->out}/\$FIELDSET_NONCE\" ] && touch \"\$STUB_ROOT/nonces-seen\"\n");

    $root = realpath($this->scripts->root);
    $process = $this->scripts->run($this->dir.'/fsa', [
        'install', '--secrets-file', 'out/link.json', '--smtp-password-file='.$this->scripts->root.'/keys/pw', '--owner-password-file', $this->scripts->root.'/keys/pw',
        '--backup-key-file', $this->dir.'/key.txt', '--from-backup', $root.'/out/backup-1', '--owner-email', 'a@b.test',
    ], [...$this->env, 'STUB_OPS_HOOK' => $hook], $this->scripts->root);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(shimVolumes($run))->toBe([
            '/var/run/docker.sock:/var/run/docker.sock', "{$this->dir}:{$this->dir}", "{$root}/keys:{$root}/keys:ro", "{$root}/out:{$root}/out",
        ])
        ->and(array_slice($run, array_search(SHIM_IMAGE, $run, true) + 1))->toBe([
            'install', '--dir', $this->dir, "--secrets-file={$root}/out/link.json", "--smtp-password-file={$root}/keys/pw", "--owner-password-file={$root}/keys/pw",
            "--backup-key-file={$this->dir}/key.txt", "--from-backup={$root}/out/backup-1", '--owner-email', 'a@b.test',
        ])
        ->and($this->scripts->opsEnvironment()['FIELDSET_MOUNTS'])->toBe("rw:{$root}/out\nro:{$root}/keys\nro:{$root}/out\n")
        ->and(is_file($this->scripts->root.'/nonces-seen'))->toBeTrue()
        ->and(glob($this->out.'/.fsa-nonce-*'))->toBe([])
        ->and(glob($this->dir.'/.fsa-nonce-*'))->toBe([]);
});

it('mounts adopt\'s 1.0 checkout read-only at its own physical path and passes that path (ADR 0087)', function (): void {
    mkdir($this->scripts->root.'/home/form-schema-app');
    $checkout = realpath($this->scripts->root.'/home/form-schema-app');

    $process = $this->scripts->run($this->dir.'/fsa', ['adopt', 'home/./form-schema-app', '--yes', '--json'], $this->env, $this->scripts->root);
    $run = $this->scripts->opsRuns()[0];
    $missing = $this->scripts->run($this->dir.'/fsa', ['adopt', $this->scripts->root.'/home/none', '--json'], $this->env);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(shimVolumes($run))->toContain("{$checkout}:{$checkout}:ro")
        ->and(array_slice($run, array_search(SHIM_IMAGE, $run, true) + 1))->toBe(['adopt', '--dir', $this->dir, $checkout, '--yes', '--json'])
        ->and($this->scripts->opsEnvironment()['FIELDSET_MOUNTS'])->toBe("ro:{$checkout}\n")
        ->and($missing->getExitCode())->toBe(2)->and($missing->getOutput())->toContain('path_missing');
});

it('never takes the value of a flag as adopt\'s checkout (review S4)', function (): void {
    mkdir($this->scripts->root.'/home/form-schema-app');
    $checkout = realpath($this->scripts->root.'/home/form-schema-app');

    $process = $this->scripts->run($this->dir.'/fsa', ['adopt', '--release', '2.0.0', 'home/form-schema-app', '--json'], $this->env, $this->scripts->root);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_slice($run, array_search(SHIM_IMAGE, $run, true) + 1))->toBe(['adopt', '--dir', $this->dir, '--release', '2.0.0', $checkout, '--json'])
        ->and($this->scripts->opsEnvironment()['FIELDSET_MOUNTS'])->toBe("ro:{$checkout}\n");
});

it('refuses file paths a bind mount cannot carry or whose directory does not exist (exit 2)', function (array $arguments, string $message): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['install', ...$arguments], $this->env);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain($message)
        ->and($this->scripts->opsRuns())->toBe([]);
})->with([
    'missing directory' => [['--secrets-file', '/nonexistent-fsa/link.json'], 'does not exist'],
    'colon' => [['--secrets-file=/tmp/a:b/link.json'], "Paths with ':', ',' or line breaks"],
    'comma' => [['--password-file', '/tmp/a,b/pw'], "Paths with ':', ',' or line breaks"],
    'root directory' => [['--secrets-file', '/link.json'], 'does not mount the root directory'],
    'missing value' => [['--secrets-file'], '--secrets-file needs a value'],
    'other instance' => [['--dir', '/tmp'], 'not /tmp'],
]);

it('mounts the physical directory behind a symlinked parent and passes the physical path', function (): void {
    mkdir($this->scripts->root.'/real');
    symlink($this->scripts->root.'/real', $this->scripts->root.'/home/link');
    $real = realpath($this->scripts->root.'/real');

    $process = $this->scripts->run($this->dir.'/fsa', ['login-link', 'a@b.test', '--secrets-file', $this->scripts->root.'/home/./link/x.json'], $this->env);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(shimVolumes($run)[2])->toBe("{$real}:{$real}")
        ->and(end($run))->toBe("--secrets-file={$real}/x.json");
});

it('refuses to mount a system path or a directory other users can write to (exit 2 path_reserved)', function (string $path): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['login-link', 'a@b.test', '--secrets-file', $path, '--json'], $this->env);
    $json = json_decode($process->getOutput(), true);

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput())
        ->and($json['errors'][0]['code'])->toBe('path_reserved')
        ->and($json['errors'][0]['retryable'])->toBeFalse()
        ->and($json['errors'][0]['hint'])->toContain('home directory')
        ->and($this->scripts->opsRuns())->toBe([])
        ->and(glob($this->dir.'/.fsa-nonce-*'))->toBe([]);
})->with([
    'shared /tmp' => ['/tmp/link.json'],
    'the app code' => ['/var/www/link.json'],
    'image binaries' => ['/usr/local/bin/link.json'],
    'device tmpfs' => ['/dev/shm/link.json'],
]);

it('accepts file flags under the home directory and refuses an instance in a reserved path', function (): void {
    mkdir($this->scripts->root.'/home/out');
    $home = realpath($this->scripts->root.'/home');
    $allowed = $this->scripts->run($this->dir.'/fsa', ['login-link', 'a@b.test', '--secrets-file', $home.'/out/link.json'], $this->env);

    $reserved = sys_get_temp_dir().'/fsa-test-reserved-'.bin2hex(random_bytes(4));
    mkdir($reserved, 0700);
    copy($this->dir.'/state.json', $reserved.'/state.json');
    copy($this->dir.'/fsa', $reserved.'/fsa');
    try {
        $refused = $this->scripts->run($reserved.'/fsa', ['status', '--json'], $this->env);
    } finally {
        exec('rm -rf '.escapeshellarg($reserved));
    }

    expect($allowed->getExitCode())->toBe(0, $allowed->getErrorOutput())
        ->and(shimVolumes($this->scripts->opsRuns()[0]))->toContain("{$home}/out:{$home}/out")
        ->and($refused->getExitCode())->toBe(2)
        ->and(json_decode($refused->getOutput(), true)['errors'][0]['code'])->toBe('path_reserved')
        ->and($this->scripts->opsRuns())->toHaveCount(1);
});

it('accepts its own directory as --dir without passing it twice', function (): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status', '--dir', '.'], $this->env, $this->dir);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_count_values($run)['--dir'])->toBe(1)
        ->and(end($run))->toBe($this->dir);
});

it('selects the user, socket and owner UID per Docker runtime (KTD2)', function (array $env, string $runtime, bool $root, string $socket): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status'], [...$this->env, ...$env]);
    $run = $this->scripts->opsRuns()[0];
    $facts = $this->scripts->opsEnvironment();
    $uid = trim((string) shell_exec('id -u'));
    $gid = trim((string) shell_exec('id -g'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($facts['FIELDSET_DOCKER_RUNTIME'])->toBe($runtime)
        ->and($run[array_search('--user', $run, true) + 1])->toBe($root ? '0:0' : "{$uid}:{$gid}")
        ->and($facts['FIELDSET_CONTAINER_UID'])->toBe($root ? '0' : $uid)
        ->and($facts['FIELDSET_DOCKER_SOCKET'])->toBe($socket)
        ->and(shimVolumes($run)[0])->toBe("{$socket}:/var/run/docker.sock");
})->with([
    'rootful Linux' => [[], 'rootful', false, '/var/run/docker.sock'],
    'rootful with a custom socket' => [['DOCKER_HOST' => 'unix:///srv/docker.sock'], 'rootful', false, '/srv/docker.sock'],
    'rootless' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|["name=seccomp,profile=builtin","name=rootless"]', 'XDG_RUNTIME_DIR' => '/run/user/1000'], 'rootless', true, '/run/user/1000/docker.sock'],
    'Docker Desktop on Linux' => [['STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'], 'desktop', true, '/var/run/docker.sock'],
    'Colima on Linux' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|colima|[]', 'DOCKER_HOST' => 'unix:///home/u/.colima/default/docker.sock'], 'desktop', true, '/var/run/docker.sock'],
    'macOS' => [['STUB_UNAME' => 'Darwin', 'DOCKER_HOST' => 'unix:///Users/u/.docker/run/docker.sock'], 'desktop', true, '/var/run/docker.sock'],
]);

it('refuses a non-local DOCKER_HOST and an unreachable daemon with exit 10', function (array $env, string $code, bool $retryable): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status', '--json'], [...$this->env, ...$env]);

    expect($process->getExitCode())->toBe(10)
        ->and(json_decode($process->getOutput(), true)['errors'][0])->toMatchArray(['code' => $code, 'retryable' => $retryable])
        ->and($this->scripts->opsRuns())->toBe([]);
})->with([
    'tcp' => [['DOCKER_HOST' => 'tcp://10.0.0.1:2375'], 'docker_host_unsupported', false],
    'daemon down (as fsa: docker_unavailable, retryable)' => [['STUB_DOCKER_DOWN' => '1'], 'docker_unavailable', true],
]);

it('passes the fsa exit code through and maps a failed docker run to exit 10', function (): void {
    $failed = $this->scripts->run($this->dir.'/fsa', ['upgrade', '--json'], [...$this->env, 'STUB_OPS_EXIT' => '125', 'STUB_OPS_STDERR' => 'docker: Error response from daemon: no such image.']);

    expect($this->scripts->run($this->dir.'/fsa', ['upgrade'], [...$this->env, 'STUB_OPS_EXIT' => '5'])->getExitCode())->toBe(5)
        ->and($failed->getExitCode())->toBe(10)
        ->and(json_decode($failed->getOutput(), true)['errors'][0])->toMatchArray(['code' => 'docker_run_failed', 'message' => 'Docker could not start the fsa container (exit 125): docker: Error response from daemon: no such image.'])
        ->and(glob($this->dir.'/.fsa-nonce-*'))->toBe([])
        ->and(glob($this->scripts->root.'/fsa-run.*'))->toBe([]);
});

it('maps Docker Desktop refusing an unshared directory to exit 3 path_not_shared, not a daemon error', function (string $stderr): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status', '--json'], [...$this->env, 'STUB_OPS_EXIT' => '125', 'STUB_OPS_STDERR' => $stderr]);
    $error = json_decode($process->getOutput(), true)['errors'][0];

    expect($process->getExitCode())->toBe(3)
        ->and($error['code'])->toBe('path_not_shared')
        ->and($error['hint'])->toContain('File sharing');
})->with([
    'mounts denied' => ['docker: Error response from daemon: Mounts denied: '],
    'not shared' => ['The path /Volumes/x is not shared from the host and is not known to Docker.'],
]);

it('keeps stderr NDJSON with --json: its own messages and Docker\'s lines become events, the command\'s events pass', function (): void {
    $hook = $this->scripts->writeExecutable('ops-hook', "#!/bin/sh\nprintf '%s\\n' '{\"ts\":\"2026-10-18T10:00:00Z\",\"phase\":\"doctor\",\"level\":\"info\",\"message\":\"ok\"}' >&2\n");
    $run = $this->scripts->run($this->dir.'/fsa', ['status', '--json'], [...$this->env, 'STUB_OPS_HOOK' => $hook, 'STUB_OPS_STDERR' => 'WARNING: plain docker text']);
    $failed = $this->scripts->run($this->dir.'/fsa', ['status', '--json', '--dir', '/elsewhere'], $this->env);
    $human = $this->scripts->run($this->dir.'/fsa', ['status', '--dir', '/elsewhere'], $this->env);
    $events = fn (string $stderr): array => array_map(fn (string $line): mixed => json_decode($line, true), explode("\n", trim($stderr)));

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($events($run->getErrorOutput()))->toHaveCount(2)
        ->and($events($run->getErrorOutput())[0]['phase'])->toBe('doctor')
        ->and($events($run->getErrorOutput())[1])->toMatchArray(['phase' => 'launcher', 'level' => 'warn', 'message' => 'WARNING: plain docker text'])
        ->and($events($failed->getErrorOutput()))->each->toHaveKeys(['ts', 'phase', 'level', 'message'])
        ->and($events($failed->getErrorOutput())[0]['level'])->toBe('error')
        ->and(json_decode($failed->getOutput(), true)['errors'][0]['code'])->toBe('other_instance')
        ->and($human->getErrorOutput())->toStartWith('fsa: This fsa manages')
        ->and($human->getErrorOutput())->toContain('  Fix: Run /elsewhere/fsa instead.')
        ->and($human->getOutput())->toBe('');
});

it('uses a terminal only for a person: no --json, stdout a terminal, and for the shim stdin too (piped input stays)', function (string $launcher, string $json, string $stdin, string $stdout, string $usable, bool $tty): void {
    $process = $this->scripts->source(base_path('distribution/shim.sh'), "fsa_wants_tty {$launcher} {$json} {$stdin} {$stdout} {$usable} && echo yes || echo no");

    expect(trim($process->getOutput()))->toBe($tty ? 'yes' : 'no');
})->with([
    'shim at a terminal' => ['shim', '0', '1', '1', '1', true],
    'shim with piped stdin' => ['shim', '0', '0', '1', '1', false],
    'shim with --json' => ['shim', '1', '1', '1', '1', false],
    'shim with stdout piped' => ['shim', '0', '1', '0', '1', false],
    'shim without a usable terminal' => ['shim', '0', '1', '1', '0', false],
    'install.sh from curl | sh' => ['install.sh', '0', '0', '1', '1', true],
    'install.sh with --json' => ['install.sh', '1', '0', '1', '1', false],
]);

it('opens a handed-over http(s) URL with the desktop opener, as one argument and without a shell', function (string $url, bool $opened): void {
    $this->scripts->writeExecutable('xdg-open', "#!/bin/sh\nprintf '%s' \"\$1\" > \"\$STUB_ROOT/opened\"\n");
    $hook = $this->scripts->writeExecutable('ops-hook', "#!/bin/sh\nprintf '%s\\n' \"\$FAKE_URL\" > \"\$FIELDSET_INSTANCE_DIR/\$FIELDSET_OPEN_URL_FILE\"\n");

    $process = $this->scripts->run($this->dir.'/fsa', ['demo'], [...$this->env, 'DISPLAY' => ':0', 'STUB_OPS_HOOK' => $hook, 'FAKE_URL' => $url]);
    $facts = $this->scripts->opsEnvironment();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($facts['FIELDSET_DESKTOP'])->toBe('1')
        ->and($facts['FIELDSET_OPEN_URL_FILE'])->toMatch('/^\.fsa-open-url-[0-9a-f]{32}$/')
        ->and(glob($this->dir.'/.fsa-open-url-*'))->toBe([])
        ->and(file_exists($this->scripts->root.'/pwned'))->toBeFalse();
    expect($opened ? file_get_contents($this->scripts->root.'/opened') : file_exists($this->scripts->root.'/opened'))->toBe($opened ? $url : false);
})->with([
    'sign-in link' => ['http://localhost:8080/login-link#token=abc-123', true],
    'command substitution stays literal' => ['https://x.test/$(touch%20pwned)', true],
    'braces' => ['https://x.test/${IFS}', false],
    'file URL' => ['file:///etc/passwd', false],
    'javascript' => ['javascript:alert(1)', false],
    'space' => ['https://x.test/a b', false],
    'quote' => ["https://x.test/'", false],
]);

it('falls back when the browser does not open: the terminal if a person is there, else the 0600 file stays', function (bool $terminal, string $json): void {
    $url = 'http://localhost:8080/login-link#token=abc-123';
    $file = '.fsa-open-url-00112233445566778899aabbccddeeff';
    file_put_contents($this->dir.'/'.$file, $url."\n");
    $tty = $terminal ? $this->scripts->root.'/tty' : $this->scripts->root.'/no-such-dir/tty';
    if ($terminal) {
        touch($tty);
    }
    $snippet = 'fsa_nl="$(printf "\\nx")"; fsa_nl="${fsa_nl%x}"; fsa_dir="'.$this->dir.'"; FIELDSET_OPEN_URL_FILE='.$file.'; fsa_opener=false; fsa_json='.$json
        .'; fsa_tty_device="'.$tty.'"; fsa_cleanup_files="$fsa_dir/'.$file.'$fsa_nl"; fsa_open_url; printf "[%s]" "$fsa_cleanup_files"';

    $process = $this->scripts->source(base_path('distribution/shim.sh'), $snippet);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_exists($this->dir.'/'.$file))->toBe(! $terminal)
        ->and($process->getOutput())->toBe($terminal ? "[{$this->dir}/{$file}\n]" : '[]');
    if ($terminal) {
        expect(file_get_contents($tty))->toContain($url)
            ->and($process->getErrorOutput())->toBe('');

        return;
    }
    $stderr = $json === '1' ? json_decode(trim($process->getErrorOutput()), true)['message'] : $process->getErrorOutput();
    expect(substr(sprintf('%o', fileperms($this->dir.'/'.$file)), -3))->toBe('600')
        ->and($stderr)->toContain("{$this->dir}/{$file}")
        ->and($stderr)->toContain('fsa login-link <email> --secrets-file <file>')
        ->and($stderr)->not->toContain('token=abc-123');
})->with([
    'person at a terminal' => [true, '0'],
    'no terminal' => [false, '0'],
    'no terminal, --json' => [false, '1'],
]);

it('offers no browser hand-over without a desktop session', function (array $env): void {
    $this->scripts->writeExecutable('xdg-open', "#!/bin/sh\ntouch \"\$STUB_ROOT/opened\"\n");
    $this->scripts->writeExecutable('open', "#!/bin/sh\ntouch \"\$STUB_ROOT/opened\"\n");

    $this->scripts->run($this->dir.'/fsa', ['demo'], [...$this->env, ...$env]);

    expect($this->scripts->opsEnvironment())->toMatchArray(['FIELDSET_DESKTOP' => '0', 'FIELDSET_OPEN_URL_FILE' => ''])
        ->and(file_exists($this->scripts->root.'/opened'))->toBeFalse();
})->with([
    'Linux without a display' => [[]],
    'macOS over SSH' => [['STUB_UNAME' => 'Darwin', 'SSH_CONNECTION' => '10.0.0.2 5000 10.0.0.1 22']],
]);

it('reports host facts: time zone, LAN addresses without bridges, and the TTY only without --json', function (): void {
    $this->scripts->writeExecutable('ip', "#!/bin/sh\nprintf '%s\\n' '2: eth0    inet 192.168.1.20/24 brd 192.168.1.255 scope global eth0' '3: docker0    inet 172.17.0.1/16 scope global docker0' '4: br-1a2b    inet 172.18.0.1/16 scope global br-1a2b' '5: wlan0    inet 10.0.0.5/24 scope global wlan0'\n");

    $this->scripts->run($this->dir.'/fsa', ['status', '--json'], [...$this->env, 'TZ' => ':America/New_York']);
    $facts = $this->scripts->opsEnvironment();
    $tz = $this->scripts->source(base_path('distribution/shim.sh'), 'fsa_timezone', ['TZ' => 'bad zone;rm']);

    expect($facts['FIELDSET_TZ'])->toBe('America/New_York')
        ->and($facts['FIELDSET_LAN_ADDRESSES'])->toBe('192.168.1.20 10.0.0.5 ')
        ->and($facts['FIELDSET_HOST_OS'])->toBe('linux')
        ->and($facts['FIELDSET_TTY'])->toBe('0')
        ->and($tz->getOutput())->toBe('');
});

it('resolves its instance directory through a PATH symlink', function (): void {
    mkdir($this->scripts->root.'/home/.local/bin', 0700, true);
    symlink($this->dir.'/fsa', $this->scripts->root.'/home/.local/bin/fsa');

    $process = $this->scripts->run($this->scripts->root.'/home/.local/bin/fsa', ['status'], $this->env);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(end($this->scripts->opsRuns()[0]))->toBe($this->dir);
});

it('classifies file-path flags exactly like FilePathFlags', function (): void {
    $names = ['secrets-file', 'output', 'backup-out', 'password-file', 'owner-password-file', 'from-backup', 'dir', 'json', 'outputs', 'version'];
    $snippet = 'for n in '.implode(' ', $names).'; do fsa_file_flag_mode "--$n"; printf "%s," "$fsa_mode"; done';

    $modes = array_slice(explode(',', $this->scripts->source(base_path('distribution/shim.sh'), $snippet)->getOutput()), 0, -1);

    expect(array_map(fn (string $mode): ?string => ['rw' => FilePathFlags::OUTPUT, 'ro' => FilePathFlags::INPUT][$mode] ?? null, $modes))
        ->toBe(array_map(FilePathFlags::classify(...), $names));
});
