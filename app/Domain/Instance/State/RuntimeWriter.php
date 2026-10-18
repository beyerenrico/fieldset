<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
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
 */
final readonly class RuntimeWriter
{
    public const string SCHEMA_VERSION = '1.0';

    public const string FILE = InstanceDirectory::RUNTIME_DIR.'/runtime.json';

    /** Mount point in the containers; the app reads it from FSA_RUNTIME_PATH (U10). */
    public const string CONTAINER_PATH = '/run/fsa-runtime';

    private const array LOWERED = ['raised' => false, 'operation' => null, 'since' => null];

    public function __construct(private InstanceDirectory $directory) {}

    /** Renders the runtime from the state, keeping the current gate. */
    public function write(InstanceState $state): void
    {
        $this->store($this->document($state, $this->currentGate()));
    }

    /** Raises the gate for an operation: queue, scheduler and backup refuse to start, the app answers 503. */
    public function raiseGate(InstanceState $state, string $operation): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $operation) !== 1) {
            throw new InstanceException('The operation name for the gate must be a plain identifier.', ExitCode::InternalError, 'invalid_operation');
        }
        $since = CarbonImmutable::now('UTC')->toIso8601ZuluString();
        $this->store($this->document($state, ['raised' => true, 'operation' => $operation, 'since' => $since]));
    }

    public function lowerGate(InstanceState $state): void
    {
        $this->store($this->document($state, self::LOWERED));
    }

    /**
     * @param  array{raised: bool, operation: string|null, since: string|null}  $gate
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
            // Written by `fsa tunnel start` (U14); the host must match ^[a-z0-9-]+\.trycloudflare\.com$.
            'tunnel' => ['hostname' => null, 'expires_at' => null],
            'gate' => $gate,
        ];
    }

    /** @param  array<string, mixed>  $document */
    private function store(array $document): void
    {
        $this->directory->ensureDirectory(InstanceDirectory::RUNTIME_DIR, 0755);
        $this->directory->write(self::FILE, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", 0644);
    }

    /** @return array{raised: bool, operation: string|null, since: string|null} the gate in the current file, else lowered */
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
        ];
    }
}
