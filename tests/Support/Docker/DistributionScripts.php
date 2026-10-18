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
 * and the ops-container dump includes that variable next to the FSA_* ones.
 */
final readonly class DistributionScripts
{
    public string $root;

    public string $bin;

    private string $log;

    public function __construct(public string $shell = '/bin/dash')
    {
        // Not under the system temp directory: the launchers refuse /tmp and /var (macOS keeps TMPDIR there) as
        // mount or instance paths (fsa_reserved_path).
        $this->root = self::repository().'/storage/fsa-test-dist/'.bin2hex(random_bytes(6));
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
        $guard = str_ends_with($script, 'install.sh') ? 'FSA_INSTALL_LIBRARY' : 'FSA_SHIM_LIBRARY';

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
     * The `docker run … --entrypoint fsa …` calls (the ops container), without the program name.
     *
     * @return list<list<string>>
     */
    public function opsRuns(): array
    {
        $runs = array_filter($this->calls('docker'), static fn (array $call): bool => ($call[1] ?? '') === 'run' && in_array('fsa', $call, true)
            && ($call[array_search('fsa', $call, true) - 1] ?? '') === '--entrypoint');

        return array_values(array_map(static fn (array $call): array => array_slice($call, 1), $runs));
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

    /** @return array<string, string> FSA_* variables and DOCKER_DEFAULT_PLATFORM the last ops container would get (dumped by the stub) */
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

    public function cleanUp(): void
    {
        exec('chmod -R u+rwx '.escapeshellarg($this->root).' 2>/dev/null; rm -rf '.escapeshellarg($this->root));
        @rmdir(dirname($this->root));
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
            'FSA_TRUST_KEY' => false,
            'FSA_RELEASE_URL' => false,
            'FSA_REGISTRY' => false,
            'DOCKER_DEFAULT_PLATFORM' => false,
            'FSA_TEST_DNS' => false,
            'DISPLAY' => false,
            'WAYLAND_DISPLAY' => false,
            'SSH_CONNECTION' => false,
            'TZ' => 'Europe/Berlin',
            ...$env,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process;
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
        : > "$STUB_ROOT/ops-env"
        for v in $(env | sed -n -e 's/^\(FSA_[A-Z_]*\)=.*/\1/p' -e 's/^\(DOCKER_DEFAULT_PLATFORM\)=.*/\1/p'); do
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
