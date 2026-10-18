<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Runtime\RuntimeDocument;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * Writes `runtime/runtime.json` (KTD11), the only channel from ops to app: the instance URL, the local URL,
 * extra hosts, the tunnel hostname and expiry, and the operation gate. The directory is mounted read-only into
 * app, queue, scheduler and every one-off; a directory rather than a file, because a single-file bind mount pins
 * the inode and would hide the atomic rename. The app runs as www-data, so the directory is 0755 and the file
 * 0644, set explicitly whatever the umask. Readers ignore unknown keys (`schema_version`).
 *
 * Re-rendering from the state keeps the gate and the tunnel as they are: only the operation that raised the gate
 * lowers it (KTD5, U18), and only `fsa tunnel` (U14) changes the tunnel. A current file that exists but cannot be
 * parsed keeps the gate raised (operation `unknown`): it may be the record of a running upgrade, and lowering it
 * blind would let traffic in mid-operation; the app fails closed on such a file anyway (ADR 0074). A file that exists
 * but cannot be read is refused (UnsafePath), never overwritten blind (ADR 0078).
 * A raised gate carries the operation's smoke token: a random secret that lets the operation's internal smoke test
 * through the app's 503 gate (App\Domain\Instance\Runtime\OperationGate, ADR 0074). Lowering the gate drops it.
 */
final readonly class RuntimeWriter
{
    public const string SCHEMA_VERSION = '1.0';

    public const string FILE = InstanceDirectory::RUNTIME_DIR.'/runtime.json';

    /** Mount point in the containers; the app reads it from FSA_RUNTIME_PATH (config instance.runtime_path, U10). */
    public const string CONTAINER_PATH = '/run/fsa-runtime';

    private const array LOWERED = ['raised' => false, 'operation' => null, 'since' => null, 'smoke_token' => null];

    private const array NO_TUNNEL = ['hostname' => null, 'expires_at' => null, 'peer' => null, 'admin' => false];

    /** The operation named by a gate kept raised because the current file could not be parsed. */
    public const string UNKNOWN_OPERATION = 'unknown';

    public function __construct(private InstanceDirectory $directory) {}

    /** Renders the runtime from the state, keeping the current gate and tunnel. */
    public function write(InstanceState $state): void
    {
        [$gate, $tunnel] = $this->current();
        $this->store($this->document($state, $gate, $tunnel));
    }

    /**
     * Raises the gate for an operation: the app answers 503 to everything but its loopback healthcheck and requests
     * carrying the returned smoke token from the internal network (header `X-Fsa-Smoke-Token`). A fresh token is
     * generated unless the caller passes one. (Queue, scheduler and backup refusing to start behind a raised gate
     * arrives with U18; until then only the app is gated.)
     */
    public function raiseGate(InstanceState $state, string $operation, ?string $smokeToken = null): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $operation) !== 1) {
            throw new InstanceException('The operation name for the gate must be a plain identifier.', ExitCode::InternalError, 'invalid_operation');
        }
        $smokeToken ??= bin2hex(random_bytes(32));
        if (preg_match(RuntimeDocument::SMOKE_TOKEN, $smokeToken) !== 1) { // the app's grammar of the token
            throw new InstanceException('The smoke token for the gate must be 32 to 128 URL-safe characters.', ExitCode::InternalError, 'invalid_smoke_token');
        }
        $since = CarbonImmutable::now('UTC')->toIso8601ZuluString();
        $this->store($this->document($state, ['raised' => true, 'operation' => $operation, 'since' => $since, 'smoke_token' => $smokeToken], $this->current()[1]));

        return $smokeToken;
    }

    public function lowerGate(InstanceState $state): void
    {
        $this->store($this->document($state, self::LOWERED, $this->current()[1]));
    }

    /**
     * @param  array{raised: bool, operation: string|null, since: string|null, smoke_token: string|null}  $gate
     * @param  array{hostname: string|null, expires_at: string|null, peer: string|null, admin: bool}  $tunnel
     * @return array<string, mixed>
     */
    public function document(InstanceState $state, array $gate = self::LOWERED, array $tunnel = self::NO_TUNNEL): array
    {
        $settings = InstanceSettings::of($state);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'instance_url' => $settings->instanceUrl(),
            'local_url' => $settings->localUrl(),
            'extra_hosts' => $settings->extraHosts(),
            // Written by `fsa tunnel start` (U14): the host must match ^[a-z0-9-]+\.trycloudflare\.com$, `peer` is
            // cloudflared's fixed address (the TCP peer of every tunnel request), `admin` opens /admin (--tunnel-admin).
            'tunnel' => $tunnel,
            'gate' => $gate,
        ];
    }

    /** @param  array<string, mixed>  $document */
    private function store(array $document): void
    {
        $this->directory->ensureDirectory(InstanceDirectory::RUNTIME_DIR, 0755);
        $this->directory->write(self::FILE, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", 0644);
    }

    /**
     * The gate and the tunnel of the current file: nothing there is a lowered gate and no tunnel; a file that cannot be
     * parsed keeps the gate raised; a file that cannot be read throws (UnsafePath) instead of being overwritten blind.
     *
     * @return array{0: array{raised: bool, operation: string|null, since: string|null, smoke_token: string|null}, 1: array{hostname: string|null, expires_at: string|null, peer: string|null, admin: bool}}
     */
    private function current(): array
    {
        $raw = $this->directory->read(self::FILE);
        if ($raw === null) {
            return [self::LOWERED, self::NO_TUNNEL];
        }

        try {
            $current = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $current = null;
        }
        $gate = is_array($current) ? ($current['gate'] ?? null) : null;
        if (! is_array($gate) || ! is_bool($gate['raised'] ?? null)) {
            return [['raised' => true, 'operation' => self::UNKNOWN_OPERATION, 'since' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'smoke_token' => null], self::NO_TUNNEL];
        }
        $string = static fn (array $values, string $key): ?string => is_string($values[$key] ?? null) ? $values[$key] : null;
        $tunnel = is_array($current['tunnel'] ?? null) ? $current['tunnel'] : [];
        $tunnel = ['hostname' => $string($tunnel, 'hostname'), 'expires_at' => $string($tunnel, 'expires_at'), 'peer' => $string($tunnel, 'peer'), 'admin' => ($tunnel['admin'] ?? false) === true];

        return [
            $gate['raised'] ? ['raised' => true, 'operation' => $string($gate, 'operation'), 'since' => $string($gate, 'since'), 'smoke_token' => $string($gate, 'smoke_token')] : self::LOWERED,
            $tunnel,
        ];
    }
}
