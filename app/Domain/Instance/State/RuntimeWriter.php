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
 * Re-rendering from the state keeps the gate as it is: only the operation that raised it lowers it (KTD5, U18).
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

    public function __construct(private InstanceDirectory $directory) {}

    /** Renders the runtime from the state, keeping the current gate. */
    public function write(InstanceState $state): void
    {
        $this->store($this->document($state, $this->currentGate()));
    }

    /**
     * Raises the gate for an operation: queue, scheduler and backup refuse to start, the app answers 503 to
     * everything but its loopback healthcheck and requests carrying the returned smoke token from the internal
     * network (header `X-Fsa-Smoke-Token`). A fresh token is generated unless the caller passes one.
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
        $this->store($this->document($state, ['raised' => true, 'operation' => $operation, 'since' => $since, 'smoke_token' => $smokeToken]));

        return $smokeToken;
    }

    public function lowerGate(InstanceState $state): void
    {
        $this->store($this->document($state, self::LOWERED));
    }

    /**
     * @param  array{raised: bool, operation: string|null, since: string|null, smoke_token: string|null}  $gate
     * @return array<string, mixed>
     */
    public function document(InstanceState $state, array $gate = self::LOWERED): array
    {
        $settings = InstanceSettings::of($state);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'instance_url' => $settings->instanceUrl(),
            'local_url' => $settings->localUrl(),
            'extra_hosts' => $settings->extraHosts(),
            // Written by `fsa tunnel start` (U14): the host must match ^[a-z0-9-]+\.trycloudflare\.com$, `peer` is
            // cloudflared's fixed address (the TCP peer of every tunnel request), `admin` opens /admin (--tunnel-admin).
            'tunnel' => ['hostname' => null, 'expires_at' => null, 'peer' => null, 'admin' => false],
            'gate' => $gate,
        ];
    }

    /** @param  array<string, mixed>  $document */
    private function store(array $document): void
    {
        $this->directory->ensureDirectory(InstanceDirectory::RUNTIME_DIR, 0755);
        $this->directory->write(self::FILE, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", 0644);
    }

    /** @return array{raised: bool, operation: string|null, since: string|null, smoke_token: string|null} the current file's gate, else lowered */
    private function currentGate(): array
    {
        try {
            $current = json_decode((string) $this->directory->read(self::FILE), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::LOWERED;
        }
        $gate = is_array($current) ? ($current['gate'] ?? null) : null;
        if (! is_array($gate) || ($gate['raised'] ?? null) !== true) {
            return self::LOWERED;
        }

        return [
            'raised' => true,
            'operation' => is_string($gate['operation'] ?? null) ? $gate['operation'] : null,
            'since' => is_string($gate['since'] ?? null) ? $gate['since'] : null,
            'smoke_token' => is_string($gate['smoke_token'] ?? null) ? $gate['smoke_token'] : null,
        ];
    }
}
