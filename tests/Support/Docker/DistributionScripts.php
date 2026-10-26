<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use Symfony\Component\Process\Process;

/**
 * Runs distribution/install.sh and distribution/shim.sh (U4) under dash with `docker` and `curl` replaced by
 * stubs that record every call, so tests see the exact argv and environment without a Docker engine. Scripts
 * can be run whole, truncated, or sourced in library mode to call one function.
 *
 * Stub behaviour is steered by environment variables: STUB_DOCKER_INFO (`docker info` output),
 * STUB_DOCKER_DOWN=1 (daemon unreachable), STUB_IMAGE_PRESENT=1, STUB_VERIFY_EXIT, STUB_PULL_EXIT,
 * STUB_OPS_EXIT, STUB_OPS_STDERR (printed on stderr by the ops run), STUB_OPS_HOOK (a script run as the ops
 * container, with the run's argv), STUB_PULL_STDERR, STUB_RELEASE_DIR (files `curl` serves by base name; a missing
 * file is HTTP 404, exit 22), STUB_CURL_EXIT (a network failure: that curl exit code, HTTP code 000) and STUB_UNAME
 * (`uname -s`). Every docker call also logs its subcommand with the DOCKER_DEFAULT_PLATFORM it saw (`dockerPlatforms()`),
 * and the ops-container dump includes that variable next to the FIELDSET_* ones.
 *
 * The owner measurement (2.1.1 U3, `docker run … --entrypoint stat`) fails like a container that cannot start (exit
 * STUB_MEASURE_EXIT, default 125, with STUB_MEASURE_STDERR) unless STUB_MEASURE_OWNER is set: then it prints that UID as
 * the owner of the nonce file and STUB_MEASURE_SOCKET (`<gid> <mode>`, default `0 660`) for the mounted socket, as
 * container root reads them. The leftover fix (`--entrypoint find`) runs STUB_RECLAIM_HOOK with the run's argv, prints
 * STUB_RECLAIM_STDERR and exits STUB_RECLAIM_EXIT (default 0). `measureRuns()` and `reclaimRuns()` return those runs.
 *
 * The launchers read the kernel release, the WSL interop marker and the mount table under FIELDSET_TEST_PROC instead of
 * /proc. It points at `<root>/proc`, empty unless a test writes there (`proc()`, `wsl()`), so a run on a WSL or Linux
 * machine sees no host of its own; WSL_DISTRO_NAME is unset unless a test passes it. Likewise FIELDSET_TEST_WINDOWS
 * (`<root>/windows`) stands for /mnt/c/Windows, where the shim looks for powershell.exe when PATH lacks it (U5).
 * DISPLAY, WAYLAND_DISPLAY, SSH_CONNECTION and SSH_TTY are unset unless a test passes them.
 */
final readonly class DistributionScripts
{
    public string $root;

    public const string WSL2_KERNEL = '6.6.87.2-microsoft-standard-WSL2';

    public const string WSL1_KERNEL = '4.4.0-19041-Microsoft';

    public string $bin;

    private string $log;

    public function __construct(public string $shell = '/bin/dash')
    {
        // Not under the system temp directory: the launchers refuse /tmp and /var (macOS keeps TMPDIR there) as
        // mount or instance paths (fieldset_reserved_path).
        $this->root = self::repository().'/storage/fieldset-test-dist/'.bin2hex(random_bytes(6));
        $this->bin = $this->root.'/bin';
        $this->log = $this->root.'/calls.log';
        mkdir($this->bin, 0700, true);
        touch($this->log);
        $this->write('docker', self::DOCKER_STUB);
        $this->write('curl', self::CURL_STUB);
        $this->write('uname', "#!/bin/sh\nif [ \"\${1:-}\" = -s ] && [ -n \"\${STUB_UNAME:-}\" ]; then echo \"\$STUB_UNAME\"; else PATH=/usr/bin:/bin exec uname \"\$@\"; fi\n");
    }

    public static function repository(): string
    {
        return TestImage::repository();
    }

    /**
     * Runs a script file with arguments.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $env
     */
    public function run(string $script, array $arguments = [], array $env = [], ?string $cwd = null): Process
    {
        return $this->process([$this->shell, $script, ...$arguments], $env, $cwd);
    }

    /**
     * Sources a script in library mode and runs a shell snippet against its functions.
     *
     * @param  array<string, string|false>  $env
     */
    public function source(string $script, string $snippet, array $env = [], ?string $cwd = null): Process
    {
        $guard = str_ends_with($script, 'install.sh') ? 'FIELDSET_INSTALL_LIBRARY' : 'FIELDSET_SHIM_LIBRARY';

        return $this->process([$this->shell, '-c', $guard.'=1; . "$0"; '.$snippet, $script], $env, $cwd);
    }

    /**
     * Every recorded stub call: [program, ...argv], optionally only one program's.
     *
     * @return list<list<string>>
     */
    public function calls(?string $program = null): array
    {
        $calls = [];
        foreach (explode("\x1e\n", (string) file_get_contents($this->log)) as $record) {
            if ($record !== '') {
                $calls[] = explode("\x1f", rtrim($record, "\x1f"));
            }
        }

        return array_values(array_filter($calls, static fn (array $call): bool => $program === null || $call[0] === $program));
    }

    /**
     * The `docker run … --entrypoint fieldset …` calls (the ops container), without the program name.
     *
     * @return list<list<string>>
     */
    public function opsRuns(): array
    {
        $runs = array_filter($this->calls('docker'), static fn (array $call): bool => ($call[1] ?? '') === 'run' && in_array('fieldset', $call, true)
            && ($call[array_search('fieldset', $call, true) - 1] ?? '') === '--entrypoint');

        return array_values(array_map(static fn (array $call): array => array_slice($call, 1), $runs));
    }

    /**
     * The `docker run … --entrypoint stat …` calls (the owner measurement, U3), without the program name.
     *
     * @return list<list<string>>
     */
    public function measureRuns(): array
    {
        return $this->runsWithEntrypoint('stat');
    }

    /**
     * The `docker run … --entrypoint find …` calls (the leftover fix of a failed 2.1.0 install, U3), without the program name.
     *
     * @return list<list<string>>
     */
    public function reclaimRuns(): array
    {
        return $this->runsWithEntrypoint('find');
    }

    /**
     * The DOCKER_DEFAULT_PLATFORM each docker call saw, in call order: [subcommand, platform or ''].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function dockerPlatforms(): array
    {
        $calls = [];
        foreach (explode("\x1e", (string) @file_get_contents($this->root.'/docker-platforms')) as $entry) {
            if ($entry !== '') {
                $calls[] = array_pad(explode("\x1f", $entry, 2), 2, '');
            }
        }

        return $calls;
    }

    /** @return array<string, string> FIELDSET_* variables and DOCKER_DEFAULT_PLATFORM the last ops container would get (dumped by the stub) */
    public function opsEnvironment(): array
    {
        $env = [];
        foreach (explode("\x1e", (string) @file_get_contents($this->root.'/ops-env')) as $entry) {
            if ($entry !== '') {
                [$key, $value] = array_pad(explode('=', $entry, 2), 2, '');
                $env[$key] = $value;
            }
        }

        return $env;
    }

    public function writeExecutable(string $name, string $contents): string
    {
        return $this->write($name, $contents);
    }

    /** Writes a file the launchers read under FIELDSET_TEST_PROC, e.g. `mounts` or `sys/kernel/osrelease`. */
    public function proc(string $file, string $contents): void
    {
        $path = $this->root.'/proc/'.$file;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }

    /** A WSL host: its kernel release and, unless null, the interop marker binfmt_misc registers. */
    public function wsl(string $kernel = self::WSL2_KERNEL, ?string $marker = 'WSLInterop'): void
    {
        $this->proc('sys/kernel/osrelease', $kernel."\n");
        if ($marker !== null) {
            $this->proc('sys/fs/binfmt_misc/'.$marker, "enabled\n");
        }
    }

    public function cleanUp(): void
    {
        // The shared parent stays (git-ignored): removing it while it is momentarily empty races another parallel
        // worker's recursive mkdir of its own root, which then fails with "mkdir(): Invalid argument".
        exec('chmod -R u+rwx '.escapeshellarg($this->root).' 2>/dev/null; rm -rf '.escapeshellarg($this->root));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env
     */
    private function process(array $command, array $env, ?string $cwd): Process
    {
        $process = new Process($command, $cwd ?? $this->root, [
            'PATH' => $this->bin.':/usr/bin:/bin:/usr/sbin:/sbin',
            'STUB_LOG' => $this->log,
            'STUB_ROOT' => $this->root,
            'STUB_SHIM' => self::repository().'/distribution/shim.sh',
            'HOME' => $this->root.'/home',
            'TMPDIR' => $this->root,
            'DOCKER_HOST' => false,
            'FIELDSET_TRUST_KEY' => false,
            'FIELDSET_RELEASE_URL' => false,
            'FIELDSET_REGISTRY' => false,
            'DOCKER_DEFAULT_PLATFORM' => false,
            'FIELDSET_TEST_DNS' => false,
            'DISPLAY' => false,
            'WAYLAND_DISPLAY' => false,
            'SSH_CONNECTION' => false,
            'SSH_TTY' => false,
            'WSL_DISTRO_NAME' => false,
            'FIELDSET_TEST_PROC' => $this->root.'/proc',
            'FIELDSET_TEST_WINDOWS' => $this->root.'/windows',
            'TZ' => 'Europe/Berlin',
            ...$env,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    /** @return list<list<string>> */
    private function runsWithEntrypoint(string $entrypoint): array
    {
        $runs = array_filter($this->calls('docker'), static fn (array $call): bool => ($call[1] ?? '') === 'run' && in_array($entrypoint, $call, true)
            && ($call[array_search($entrypoint, $call, true) - 1] ?? '') === '--entrypoint');

        return array_values(array_map(static fn (array $call): array => array_slice($call, 1), $runs));
    }

    private function write(string $name, string $contents): string
    {
        file_put_contents($this->bin.'/'.$name, $contents);
        chmod($this->bin.'/'.$name, 0755);

        return $this->bin.'/'.$name;
    }

    private const string RECORD = <<<'SH'
        record() { for a in "$@"; do printf '%s\037' "$a"; done >> "$STUB_LOG"; printf '\036\n' >> "$STUB_LOG"; }
        SH;

    private const string DOCKER_STUB = "#!/bin/sh\n".self::RECORD."\n".<<<'SH_WRAP'
    record docker "$@"
    printf '%s\037%s\036' "$1" "${DOCKER_DEFAULT_PLATFORM:-}" >> "$STUB_ROOT/docker-platforms"
    [ "${STUB_DOCKER_DOWN:-0}" = 0 ] || { echo 'Cannot connect to the Docker daemon' >&2; exit 1; }
    case $1 in
    version) echo 29.0.0 ;;
    info) printf '%s\n' "${STUB_DOCKER_INFO:-Ubuntu 24.04 LTS|host|[\"name=seccomp,profile=builtin\"]}" ;;
    image) [ "${STUB_IMAGE_PRESENT:-0}" = 1 ] || exit 1 ;;
    pull) [ -z "${STUB_PULL_STDERR:-}" ] || printf '%s\n' "$STUB_PULL_STDERR" >&2; exit "${STUB_PULL_EXIT:-0}" ;;
    create) echo stub-container ;;
    cp) [ -d "$2" ] && cp -R "$2" "$STUB_ROOT/verified" ;;
    start) [ "${STUB_VERIFY_EXIT:-0}" = 0 ] && echo 'Verified OK' >&2 || echo 'Error: invalid signature when validating ASN.1 encoded signature' >&2; exit "${STUB_VERIFY_EXIT:-0}" ;;
    rm) ;;
    run)
        for a in "$@"; do
            [ "$a" != /var/www/html/distribution/shim.sh ] || exec cat "$STUB_SHIM"
        done
        case " $* " in
        *' --entrypoint stat '*)
            [ -n "${STUB_MEASURE_OWNER:-}" ] || { printf '%s\n' "${STUB_MEASURE_STDERR:-docker: Error response from daemon: the stub measures nothing unless STUB_MEASURE_OWNER is set.}" >&2; exit "${STUB_MEASURE_EXIT:-125}"; }
            printf '%s 0 600\n0 %s\n' "$STUB_MEASURE_OWNER" "${STUB_MEASURE_SOCKET:-0 660}"
            exit 0 ;;
        *' --entrypoint find '*)
            [ -z "${STUB_RECLAIM_HOOK:-}" ] || "$STUB_RECLAIM_HOOK" "$@"
            [ -z "${STUB_RECLAIM_STDERR:-}" ] || printf '%s\n' "$STUB_RECLAIM_STDERR" >&2
            exit "${STUB_RECLAIM_EXIT:-0}" ;;
        esac
        : > "$STUB_ROOT/ops-env"
        for v in $(env | sed -n -e 's/^\(FIELDSET_[A-Z_]*\)=.*/\1/p' -e 's/^\(DOCKER_DEFAULT_PLATFORM\)=.*/\1/p'); do
            eval "val=\${$v}"
            printf '%s=%s\036' "$v" "$val" >> "$STUB_ROOT/ops-env"
        done
        [ -z "${STUB_OPS_HOOK:-}" ] || "$STUB_OPS_HOOK" "$@"
        [ -z "${STUB_OPS_STDERR:-}" ] || printf '%s\n' "$STUB_OPS_STDERR" >&2
        exit "${STUB_OPS_EXIT:-0}" ;;
    esac
    SH_WRAP;

    private const string CURL_STUB = "#!/bin/sh\n".self::RECORD."\n".<<<'SH'
        record curl "$@"
        out='' url='' write=''
        while [ $# -gt 0 ]; do
            case $1 in -o) out=$2; shift ;; -w) write=$2; shift ;; http://* | https://*) url=$1 ;; esac
            shift
        done
        code() { [ -z "$write" ] || printf '%s' "$1"; }
        if [ -n "${STUB_CURL_EXIT:-}" ]; then
            echo "curl: (${STUB_CURL_EXIT}) Could not connect to server" >&2
            code 000
            exit "$STUB_CURL_EXIT"
        fi
        src=${STUB_RELEASE_DIR:-/nonexistent}/${url##*/}
        [ -f "$src" ] || { echo "curl: (22) The requested URL returned error: 404" >&2; code 404; exit 22; }
        cp "$src" "$out"
        code 200
        SH;
}
