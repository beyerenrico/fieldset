<?php

declare(strict_types=1);

namespace App\Domain\Instance;

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Release\ImagePin;

/**
 * Facts about the host that the ops container cannot observe itself, and how it was launched. The shim and
 * install.sh pass them as environment variables (BUILD_PROMPT_V2 §6.2, KTD2, ADR 0070). Invalid values are
 * dropped (null or empty), never trusted:
 *
 * - FIELDSET_LAUNCHER: `install.sh` or `shim`
 * - FIELDSET_TTY: 1/0, whether the ops container got a terminal (`-it` only then; never with --json)
 * - FIELDSET_HOST_UID / FIELDSET_HOST_GID: the invoking host user (recorded in the journal and the state)
 * - FIELDSET_HOST_OS: `uname -s` in lower case, e.g. linux, darwin
 * - FIELDSET_INSTANCE_DIR: the instance directory, mounted at the same absolute path
 * - FIELDSET_LAN_ADDRESSES: space-separated candidate LAN addresses of the host (the ops container sees only its bridge)
 * - FIELDSET_DESKTOP: 1 when a desktop session can open a browser (the shim opens what a command hands over in
 *   FIELDSET_OPEN_URL_FILE, ADR 0070)
 * - FIELDSET_TZ: the host time zone (IANA name)
 * - FIELDSET_DOCKER_RUNTIME: rootful | rootless | desktop (DockerRuntime)
 * - FIELDSET_CONTAINER_UID: the in-container owner UID (host UID on rootful, 0 otherwise)
 * - FIELDSET_DOCKER_SOCKET: the socket path the daemon mounted into the ops container
 * - FIELDSET_NONCE: name of the nonce file the launcher wrote into the instance directory and every writable mount
 * - FIELDSET_MOUNTS: one `ro:<path>` or `rw:<path>` line per parent directory of a file-path flag
 * - FIELDSET_OPEN_URL_FILE: name of the file in the instance directory where a command hands a URL to the shim
 * - FIELDSET_RELEASE_MANIFEST: install.sh only, the verified `<dir>/releases/<version>/release-manifest.json`
 * - FIELDSET_REGISTRY: a registry mirror every image is pulled from instead of its own (ADR 0050)
 * - FIELDSET_RELEASE_URL: where releases are downloaded from instead of GitHub Releases (install.sh's mirror; `fsa upgrade`
 *   reads the target's manifest there, ADR 0086)
 * - DOCKER_DEFAULT_PLATFORM: Docker's own variable, passed through when the caller set it: `linux/amd64` or
 *   `linux/arm64`, optionally with a variant (`linux/arm64/v8`). The ops container ran as that platform, and every
 *   docker and compose process fsa starts inherits it, so the images are pulled and run as that platform (another
 *   architecture than the engine's runs emulated; the doctor's architecture check reports it). Cosign runs as the
 *   engine's own platform (ContainerSpec::$enginePlatform)
 */
final readonly class HostFacts
{
    public const string NONCE_PATTERN = '/^\.fsa-nonce-[0-9a-f]{16,64}$/D';

    public const string OPEN_URL_PATTERN = '/^\.fsa-open-url-[0-9a-f]{16,64}$/D';

    public const string PLATFORM_PATTERN = '/^linux\/(amd64|arm64)(\/v[1-9])?$/D';

    private const string TIMEZONE_PATTERN = '/^[A-Za-z][A-Za-z0-9_+-]*(\/[A-Za-z0-9_+-]+){0,2}$/D';

    /**
     * @param  list<string>  $lanAddresses
     * @param  array<string, bool>  $mounts  absolute directory => writable
     */
    public function __construct(
        public ?bool $tty = null,
        public ?int $uid = null,
        public ?int $gid = null,
        public ?string $os = null,
        public ?string $instanceDir = null,
        public ?string $launcher = null,
        public array $lanAddresses = [],
        public ?bool $desktop = null,
        public ?string $timezone = null,
        public ?DockerRuntime $runtime = null,
        public ?int $containerUid = null,
        public ?string $socket = null,
        public ?string $nonce = null,
        public array $mounts = [],
        public ?string $openUrlFile = null,
        public ?string $releaseManifest = null,
        public ?string $registry = null,
        public ?string $platform = null,
        public ?string $releaseUrl = null,
    ) {}

    /** @param  array<string, string>  $env  e.g. getenv() */
    public static function fromEnvironment(array $env): self
    {
        $int = static fn (string $key): ?int => isset($env[$key]) && ctype_digit($env[$key]) ? (int) $env[$key] : null;
        $bool = static fn (string $key): ?bool => isset($env[$key]) ? filter_var($env[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $match = static fn (string $key, string $pattern): ?string => isset($env[$key]) && preg_match($pattern, $env[$key]) === 1 ? $env[$key] : null;

        return new self(
            tty: $bool('FIELDSET_TTY'),
            uid: $int('FIELDSET_HOST_UID'),
            gid: $int('FIELDSET_HOST_GID'),
            os: $match('FIELDSET_HOST_OS', '/^[a-z0-9_-]{1,32}$/D'),
            instanceDir: self::absolutePath($env['FIELDSET_INSTANCE_DIR'] ?? null),
            launcher: $match('FIELDSET_LAUNCHER', '/^(install\.sh|shim)$/D'),
            lanAddresses: self::addresses($env['FIELDSET_LAN_ADDRESSES'] ?? ''),
            desktop: $bool('FIELDSET_DESKTOP'),
            timezone: $match('FIELDSET_TZ', self::TIMEZONE_PATTERN),
            runtime: DockerRuntime::tryFrom($env['FIELDSET_DOCKER_RUNTIME'] ?? ''),
            containerUid: $int('FIELDSET_CONTAINER_UID'),
            socket: self::absolutePath($env['FIELDSET_DOCKER_SOCKET'] ?? null),
            nonce: $match('FIELDSET_NONCE', self::NONCE_PATTERN),
            mounts: self::mounts($env['FIELDSET_MOUNTS'] ?? ''),
            openUrlFile: $match('FIELDSET_OPEN_URL_FILE', self::OPEN_URL_PATTERN),
            releaseManifest: self::absolutePath($env['FIELDSET_RELEASE_MANIFEST'] ?? null),
            registry: $match('FIELDSET_REGISTRY', '/'.ImagePin::REGISTRY_PATTERN.'/D'),
            platform: $match('DOCKER_DEFAULT_PLATFORM', self::PLATFORM_PATTERN),
            releaseUrl: $match('FIELDSET_RELEASE_URL', '#^https?://[A-Za-z0-9._~:/%-]+$#D'),
        );
    }

    /** The host UID of the caller: from the shim, else this process's UID (the shim runs us as the host user). */
    public function callerUid(): int
    {
        return $this->uid ?? (function_exists('posix_getuid') ? posix_getuid() : 0);
    }

    public function callerGid(): int
    {
        return $this->gid ?? (function_exists('posix_getgid') ? posix_getgid() : 0);
    }

    /** Whether a prompt can be shown: the launcher attached a terminal, or (no launcher) the input is interactive. */
    public function hasTerminal(bool $interactiveInput): bool
    {
        return $this->tty ?? $interactiveInput;
    }

    /** The in-container owner UID (KTD2): as reported, else derived from the runtime, else this process's UID. */
    public function ownerUidInContainers(): int
    {
        return $this->containerUid ?? $this->runtime?->containerOwnerUid($this->callerUid()) ?? $this->callerUid();
    }

    /** The `linux/<arch>` DOCKER_DEFAULT_PLATFORM selects (without its variant), or null for the engine's own. */
    public function requestedPlatform(): ?string
    {
        return $this->platform === null ? null : implode('/', array_slice(explode('/', $this->platform), 0, 2));
    }

    /** Started by the shim or install.sh, which wrote a nonce and mounted the directories it lists. */
    public function launched(): bool
    {
        return $this->nonce !== null;
    }

    /** @return list<string> */
    public function writableMounts(): array
    {
        return array_keys(array_filter($this->mounts));
    }

    private static function absolutePath(?string $path): ?string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || preg_match('/[\x00-\x1f]/', $path) === 1) {
            return null;
        }

        return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    }

    /** @return list<string> */
    private static function addresses(string $list): array
    {
        $valid = array_filter(preg_split('/\s+/', trim($list)) ?: [], static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false);

        return array_values(array_unique($valid));
    }

    /** @return array<string, bool> */
    private static function mounts(string $lines): array
    {
        $mounts = [];
        foreach (preg_split('/\n/', $lines) ?: [] as $line) {
            if (preg_match('/^(ro|rw):(\/.*)$/D', $line, $match) === 1 && ($path = self::absolutePath($match[2])) !== null) {
                $mounts[$path] = ($mounts[$path] ?? false) || $match[1] === 'rw';
            }
        }

        return $mounts;
    }
}
