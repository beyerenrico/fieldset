<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FilePathFlags;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Docker\DistributionScripts;

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
        ->and(array_slice($run, array_search('--entrypoint', $run, true)))->toBe(['--entrypoint', 'fsa', SHIM_IMAGE, 'status', '--json', '--dir', $this->dir])
        ->and($run[1])->toBe('-i')
        ->and($run)->not->toContain('-it')
        ->and($this->scripts->opsEnvironment())->toMatchArray(['FSA_LAUNCHER' => 'shim', 'FSA_TTY' => '0', 'FSA_INSTANCE_DIR' => $this->dir]);
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
    $hook = $this->scripts->writeExecutable('ops-hook', "#!/bin/sh\n[ -f \"\$FSA_INSTANCE_DIR/\$FSA_NONCE\" ] && [ -f \"{$this->out}/\$FSA_NONCE\" ] && touch \"\$STUB_ROOT/nonces-seen\"\n");

    $root = realpath($this->scripts->root);
    $process = $this->scripts->run($this->dir.'/fsa', [
        'install', '--secrets-file', 'out/link.json', '--smtp-password-file='.$this->scripts->root.'/keys/pw', '--owner-password-file', $this->scripts->root.'/keys/pw',
        '--backup-key-file', $this->dir.'/key.txt', '--from-backup', $root.'/out/backup-1', '--owner-email', 'a@b.test',
    ], [...$this->env, 'STUB_OPS_HOOK' => $hook], $this->scripts->root);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(shimVolumes($run))->toBe([
            '/var/run/docker.sock:/var/run/docker.sock', "{$this->dir}:{$this->dir}", "{$this->scripts->root}/keys:{$this->scripts->root}/keys:ro", "{$root}/out:{$root}/out",
        ])
        ->and(array_slice($run, array_search(SHIM_IMAGE, $run, true) + 1))->toBe([
            'install', "--secrets-file={$root}/out/link.json", "--smtp-password-file={$this->scripts->root}/keys/pw", "--owner-password-file={$this->scripts->root}/keys/pw",
            "--backup-key-file={$this->dir}/key.txt", "--from-backup={$root}/out/backup-1", '--owner-email', 'a@b.test', '--dir', $this->dir,
        ])
        ->and($this->scripts->opsEnvironment()['FSA_MOUNTS'])->toBe("rw:{$root}/out\nro:{$this->scripts->root}/keys\nro:{$root}/out\n")
        ->and(is_file($this->scripts->root.'/nonces-seen'))->toBeTrue()
        ->and(glob($this->out.'/.fsa-nonce-*'))->toBe([])
        ->and(glob($this->dir.'/.fsa-nonce-*'))->toBe([]);
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
        ->and($facts['FSA_DOCKER_RUNTIME'])->toBe($runtime)
        ->and($run[array_search('--user', $run, true) + 1])->toBe($root ? '0:0' : "{$uid}:{$gid}")
        ->and($facts['FSA_CONTAINER_UID'])->toBe($root ? '0' : $uid)
        ->and($facts['FSA_DOCKER_SOCKET'])->toBe($socket)
        ->and(shimVolumes($run)[0])->toBe("{$socket}:/var/run/docker.sock");
})->with([
    'rootful Linux' => [[], 'rootful', false, '/var/run/docker.sock'],
    'rootful with a custom socket' => [['DOCKER_HOST' => 'unix:///srv/docker.sock'], 'rootful', false, '/srv/docker.sock'],
    'rootless' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|["name=seccomp,profile=builtin","name=rootless"]', 'XDG_RUNTIME_DIR' => '/run/user/1000'], 'rootless', true, '/run/user/1000/docker.sock'],
    'Docker Desktop on Linux' => [['STUB_DOCKER_INFO' => 'Docker Desktop|docker-desktop|[]'], 'desktop', true, '/var/run/docker.sock'],
    'Colima on Linux' => [['STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|colima|[]', 'DOCKER_HOST' => 'unix:///home/u/.colima/default/docker.sock'], 'desktop', true, '/var/run/docker.sock'],
    'macOS' => [['STUB_UNAME' => 'Darwin', 'DOCKER_HOST' => 'unix:///Users/u/.docker/run/docker.sock'], 'desktop', true, '/var/run/docker.sock'],
]);

it('refuses a non-local DOCKER_HOST and an unreachable daemon with exit 10', function (array $env, string $code): void {
    $process = $this->scripts->run($this->dir.'/fsa', ['status', '--json'], [...$this->env, ...$env]);

    expect($process->getExitCode())->toBe(10)
        ->and(json_decode($process->getOutput(), true)['errors'][0]['code'])->toBe($code)
        ->and($this->scripts->opsRuns())->toBe([]);
})->with([
    'tcp' => [['DOCKER_HOST' => 'tcp://10.0.0.1:2375'], 'docker_host_unsupported'],
    'daemon down' => [['STUB_DOCKER_DOWN' => '1'], 'docker_unreachable'],
]);

it('passes the fsa exit code through and maps a failed docker run to exit 10', function (): void {
    expect($this->scripts->run($this->dir.'/fsa', ['upgrade'], [...$this->env, 'STUB_OPS_EXIT' => '5'])->getExitCode())->toBe(5)
        ->and($this->scripts->run($this->dir.'/fsa', ['upgrade'], [...$this->env, 'STUB_OPS_EXIT' => '125'])->getExitCode())->toBe(10)
        ->and(glob($this->dir.'/.fsa-nonce-*'))->toBe([]);
});

it('opens a handed-over http(s) URL with the desktop opener, as one argument and without a shell', function (string $url, bool $opened): void {
    $this->scripts->writeExecutable('xdg-open', "#!/bin/sh\nprintf '%s' \"\$1\" > \"\$STUB_ROOT/opened\"\n");
    $hook = $this->scripts->writeExecutable('ops-hook', "#!/bin/sh\nprintf '%s\\n' \"\$FAKE_URL\" > \"\$FSA_INSTANCE_DIR/\$FSA_OPEN_URL_FILE\"\n");

    $process = $this->scripts->run($this->dir.'/fsa', ['demo'], [...$this->env, 'DISPLAY' => ':0', 'STUB_OPS_HOOK' => $hook, 'FAKE_URL' => $url]);
    $facts = $this->scripts->opsEnvironment();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($facts['FSA_DESKTOP'])->toBe('1')
        ->and($facts['FSA_OPEN_URL_FILE'])->toMatch('/^\.fsa-open-url-[0-9a-f]{32}$/')
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

it('offers no browser hand-over without a desktop session', function (array $env): void {
    $this->scripts->writeExecutable('xdg-open', "#!/bin/sh\ntouch \"\$STUB_ROOT/opened\"\n");
    $this->scripts->writeExecutable('open', "#!/bin/sh\ntouch \"\$STUB_ROOT/opened\"\n");

    $this->scripts->run($this->dir.'/fsa', ['demo'], [...$this->env, ...$env]);

    expect($this->scripts->opsEnvironment())->toMatchArray(['FSA_DESKTOP' => '0', 'FSA_OPEN_URL_FILE' => ''])
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

    expect($facts['FSA_TZ'])->toBe('America/New_York')
        ->and($facts['FSA_LAN_ADDRESSES'])->toBe('192.168.1.20 10.0.0.5 ')
        ->and($facts['FSA_HOST_OS'])->toBe('linux')
        ->and($facts['FSA_TTY'])->toBe('0')
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
