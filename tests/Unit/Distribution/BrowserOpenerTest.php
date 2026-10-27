<?php

declare(strict_types=1);

use App\Domain\Instance\State\InstanceState;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\DistributionScripts;

/*
 * 2.1.1 U5: the browser the shim opens a handed-over URL with (R9, KTD8, ADR 0070). macOS uses `open`, a Linux desktop
 * `xdg-open`, WSL 2 with interop the Windows default browser through powershell.exe (also when WSLg sets DISPLAY); nothing
 * opens over SSH or on WSL without interop. powershell.exe's exit code says nothing, so on WSL the link always goes to
 * the terminal too. fieldset_open_url's URL grammar stays the boundary: the URL reaches PowerShell inside single quotes,
 * as one argument, never through a shell. The Windows directory is simulated under FIELDSET_TEST_WINDOWS.
 */

const OPENER_SHIM_IMAGE = 'ghcr.io/beyerenrico/fieldset@sha256:1111111111111111111111111111111111111111111111111111111111111111';

const OPENER_POWERSHELL = 'System32/WindowsPowerShell/v1.0/powershell.exe';

const OPENER_SIGN_IN = 'http://localhost:8080/login-link#token=abc-123';

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    mkdir($this->scripts->root.'/home');
    $this->dir = $this->scripts->root.'/home/fieldset';
    mkdir($this->dir, 0700);
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $this->dir, 1000, 1000, 1000)
        ->withRelease('2.1.1', ['app' => OPENER_SHIM_IMAGE, 'postgres' => 'docker.io/library/postgres@sha256:'.str_repeat('2', 64)]);
    file_put_contents($this->dir.'/state.json', $state->toJson());
    copy(base_path('distribution/shim.sh'), $this->dir.'/fieldset');
    chmod($this->dir.'/fieldset', 0700);
    $this->env = ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]'];
    $this->tty = $this->scripts->root.'/tty';
});

afterEach(fn () => $this->scripts->cleanUp());

/**
 * A browser opener stub that records its argv in <root>/opened-<record> and exits with $exit: `windows` stands for
 * powershell.exe at its Windows path (not on PATH), `windows-noexec` for one there that is not executable.
 */
function openerStub(DistributionScripts $scripts, string $stub, int $exit = 0): void
{
    $record = $stub === 'windows' ? 'windows-powershell.exe' : $stub;
    $script = "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\037' \"\$a\"; done > \"\$STUB_ROOT/opened-{$record}\"\nexit {$exit}\n";
    if (! str_starts_with($stub, 'windows')) {
        $scripts->writeExecutable($stub, $script);

        return;
    }
    $path = $scripts->root.'/windows/'.OPENER_POWERSHELL;
    mkdir(dirname($path), 0700, true);
    file_put_contents($path, $script);
    chmod($path, $stub === 'windows' ? 0755 : 0644);
}

/** @return list<string>|null the argv a stub received, null when it did not run */
function openerArgs(DistributionScripts $scripts, string $record): ?array
{
    $file = $scripts->root.'/opened-'.$record;

    return is_file($file) ? explode("\x1f", rtrim((string) file_get_contents($file), "\x1f")) : null;
}

/** @return list<string> the argv powershell.exe gets for a URL */
function openerPowershellArgs(string $url): array
{
    return ['-NoProfile', '-NonInteractive', '-Command', "Start-Process '{$url}'"];
}

/**
 * Detects the host, picks the opener and hands $url over as fieldset_run does after a command, with the terminal at
 * $tty (null: no terminal).
 *
 * @param  array<string, string|false>  $env
 */
function openerHandOver(DistributionScripts $scripts, string $dir, string $url, array $env, ?string $tty): Process
{
    $file = '.fieldset-open-url-00112233445566778899aabbccddeeff';
    file_put_contents($dir.'/'.$file, $url."\n");
    $snippet = 'fieldset_nl="$(printf "\\nx")"; fieldset_nl="${fieldset_nl%x}"; fieldset_dir="$D"; FIELDSET_HOST_OS=$(uname -s | tr "[:upper:]" "[:lower:]")'
        .'; fieldset_wsl_check; fieldset_browser_opener; FIELDSET_OPEN_URL_FILE='.$file.'; fieldset_json=0; fieldset_tty_device="$T"'
        .'; fieldset_cleanup_files="$fieldset_dir/'.$file.'$fieldset_nl"; fieldset_open_url';

    return $scripts->source(base_path('distribution/shim.sh'), $snippet, [...$env, 'D' => $dir, 'T' => $tty ?? $scripts->root.'/no-such-dir/tty']);
}

it('picks the opener per host: open, xdg-open, powershell.exe on WSL with interop, nothing over SSH or without interop', function (array $env, array $stubs, ?string $marker, ?string $opens): void {
    foreach ($stubs as $stub) {
        openerStub($this->scripts, $stub);
    }
    if ($marker !== null) {
        $this->scripts->wsl(DistributionScripts::WSL2_KERNEL, in_array($marker, ['', 'disabled'], true) ? null : $marker);
    }
    if ($marker === 'disabled') {
        $this->scripts->proc('sys/fs/binfmt_misc/WSLInterop', "disabled\ninterpreter /init\nflags: PF\noffset 0\nmagic 4d5a\n");
    }
    $env = [...$this->env, ...$env];

    $run = $this->scripts->run($this->dir.'/fieldset', ['status'], $env);
    $facts = $this->scripts->opsEnvironment();
    $handOver = openerHandOver($this->scripts, $this->dir, OPENER_SIGN_IN, $env, $this->tty);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($facts['FIELDSET_DESKTOP'])->toBe($opens === null ? '0' : '1')
        ->and($facts['FIELDSET_OPEN_URL_FILE'])->toMatch($opens === null ? '/^$/' : '/^\.fieldset-open-url-[0-9a-f]{32}$/')
        ->and($handOver->getExitCode())->toBe(0, $handOver->getErrorOutput());
    foreach (['open', 'xdg-open', 'powershell.exe', 'windows-powershell.exe'] as $record) {
        $expected = $record !== $opens ? null : (str_contains($record, 'powershell') ? openerPowershellArgs(OPENER_SIGN_IN) : [OPENER_SIGN_IN]);
        expect(openerArgs($this->scripts, $record))->toBe($expected, $record);
    }
})->with([
    'macOS desktop' => [['STUB_UNAME' => 'Darwin'], ['open', 'xdg-open'], null, 'open'],
    'Linux desktop' => [['DISPLAY' => ':0'], ['xdg-open'], null, 'xdg-open'],
    'Linux desktop on Wayland' => [['WAYLAND_DISPLAY' => 'wayland-0'], ['xdg-open'], null, 'xdg-open'],
    'Linux without a display' => [[], ['xdg-open'], null, null],
    'WSL with interop' => [[], ['powershell.exe'], 'WSLInterop', 'powershell.exe'],
    'WSL, interop registered late' => [[], ['powershell.exe'], 'WSLInterop-late', 'powershell.exe'],
    'WSL with WSLg: DISPLAY set, xdg-open present' => [['DISPLAY' => ':0', 'WAYLAND_DISPLAY' => 'wayland-0'], ['xdg-open', 'powershell.exe'], 'WSLInterop', 'powershell.exe'],
    'WSL, powershell.exe only at the Windows path (PATH without Windows directories)' => [[], ['windows'], 'WSLInterop', 'windows-powershell.exe'],
    'WSL without interop, WSLg on' => [['WSL_DISTRO_NAME' => 'Ubuntu', 'DISPLAY' => ':0'], ['xdg-open', 'powershell.exe', 'windows'], '', null],
    'WSL with interop switched off at runtime (entry disabled)' => [[], ['powershell.exe', 'windows'], 'disabled', null],
    'WSL, powershell.exe not executable' => [[], ['windows-noexec'], 'WSLInterop', null],
    'macOS over SSH' => [['STUB_UNAME' => 'Darwin', 'SSH_CONNECTION' => '10.0.0.2 5000 10.0.0.1 22'], ['open'], null, null],
    'Linux over SSH with X forwarding' => [['DISPLAY' => 'localhost:10.0', 'SSH_TTY' => '/dev/pts/3'], ['xdg-open'], null, null],
    'WSL over SSH' => [['SSH_CONNECTION' => '10.0.0.2 5000 10.0.0.1 22'], ['powershell.exe'], 'WSLInterop', null],
]);

it('hands the URL to powershell.exe unchanged inside single quotes, and always prints the link to the terminal', function (string $url): void {
    $this->scripts->wsl();
    openerStub($this->scripts, 'powershell.exe');

    $process = openerHandOver($this->scripts, $this->dir, $url, $this->env, $this->tty);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(openerArgs($this->scripts, 'powershell.exe'))->toBe(openerPowershellArgs($url))
        ->and((string) file_get_contents($this->tty))->toContain("  {$url}\n")->toContain('Windows browser')->not->toContain('could not open')
        ->and(glob($this->dir.'/.fieldset-open-url-*'))->toBe([])
        ->and(file_exists($this->dir.'/pwned') || file_exists($this->scripts->root.'/pwned'))->toBeFalse()
        ->and($process->getErrorOutput())->toBe('');
})->with([
    'sign-in link' => [OPENER_SIGN_IN],
    'ampersand and percent' => ['https://x.test/login-link?a=1&b=%27x%27%20y#token=a%26b'],
    'PowerShell and shell syntax stays literal' => ['https://x.test/$(touch%20pwned);$env:TEMP&(calc)'],
]);

it('ignores powershell.exe\'s exit code: the link goes to the terminal, or stays in the 0600 file without one', function (int $exit, bool $terminal): void {
    $this->scripts->wsl();
    openerStub($this->scripts, 'powershell.exe', $exit);
    $file = $this->dir.'/.fieldset-open-url-00112233445566778899aabbccddeeff';

    $process = openerHandOver($this->scripts, $this->dir, OPENER_SIGN_IN, $this->env, $terminal ? $this->tty : null);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(openerArgs($this->scripts, 'powershell.exe'))->toBe(openerPowershellArgs(OPENER_SIGN_IN))
        ->and(file_exists($file))->toBe(! $terminal);
    if ($terminal) {
        expect((string) file_get_contents($this->tty))->toContain('  '.OPENER_SIGN_IN."\n")
            ->and($process->getErrorOutput())->toBe('');

        return;
    }
    expect(substr(sprintf('%o', fileperms($file)), -3))->toBe('600')
        ->and($process->getErrorOutput())->toContain('Windows browser')->toContain($file)
        ->and($process->getErrorOutput())->toContain('fieldset login-link <email> --secrets-file <file>')
        ->and($process->getErrorOutput())->not->toContain('token=abc-123');
})->with([
    'exit 0, terminal' => [0, true],
    'exit 1 (unreliable), terminal' => [1, true],
    'exit 1, no terminal' => [1, false],
]);

it('rejects a URL outside the grammar before powershell.exe runs, and warns', function (string $url): void {
    $this->scripts->wsl();
    openerStub($this->scripts, 'powershell.exe');

    $process = openerHandOver($this->scripts, $this->dir, $url, [...$this->env, 'LC_ALL' => PHP_OS_FAMILY === 'Darwin' ? 'en_US.UTF-8' : 'C.UTF-8'], $this->tty);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(openerArgs($this->scripts, 'powershell.exe'))->toBeNull()
        ->and($process->getErrorOutput())->toContain('Ignored a hand-over URL that is not a plain http(s) link.')
        ->and(file_exists($this->tty))->toBeFalse()
        ->and(glob($this->dir.'/.fieldset-open-url-*'))->toBe([]);
})->with([
    'single quote' => ["https://x.test/');Start-Process calc;('"],
    'typographic quote PowerShell also ends a string with' => ["https://x.test/\u{2019};calc;\u{2019}"],
    'non-ASCII letter' => ["https://x.test/\u{00E9}"],
    'backtick' => ['https://x.test/`n'],
    'space' => ['https://x.test/a b'],
]);
