<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Output\InvalidInput;
use SensitiveParameter;

/**
 * APP keys a restore may use (R42, R67, KTD16, KTD18, plan U17, ADR 0085): the instance's own (env/app.env: APP_KEY and
 * APP_PREVIOUS_KEYS) and those of a key file the operator passes (`--app-key-file`), never one carried inside the archive
 * for the HMAC. A key file holds keys one per line (`base64:…`), or is an env file (`APP_KEY=…`, `APP_PREVIOUS_KEYS=…`),
 * e.g. the old host's env/app.env. Each key is a Laravel AES-256 key: `base64:` and 32 bytes.
 */
final readonly class AppKeys
{
    private const string KEY = '/^base64:[A-Za-z0-9+\/]{43}=$/D';

    /** @param  list<string>  $keys */
    private function __construct(public array $keys) {}

    /** @param  list<string>  $keys */
    public static function of(#[SensitiveParameter] array $keys): self
    {
        return new self(array_values(array_unique(array_filter($keys, self::valid(...)))));
    }

    /**
     * @throws InvalidInput exit 2 when the file holds no valid key or a line that is none
     */
    public static function fromFile(#[SensitiveParameter] string $contents): self
    {
        $keys = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Z_][A-Z0-9_]*)=(.*)$/D', $line, $match) === 1) {
                $keys = [...$keys, ...self::fromEnvLine($match[1], trim($match[2], " \"'"))];

                continue;
            }
            if (! self::valid($line)) {
                throw self::invalid();
            }
            $keys[] = $line;
        }
        if ($keys === []) {
            throw self::invalid();
        }

        return self::of($keys);
    }

    public static function valid(#[SensitiveParameter] string $key): bool
    {
        return preg_match(self::KEY, $key) === 1 && strlen((string) base64_decode(substr($key, 7), true)) === 32;
    }

    public function merge(self $other): self
    {
        return self::of([...$this->keys, ...$other->keys]);
    }

    /** @return list<string> */
    public function fingerprints(): array
    {
        return array_map(KeyFingerprint::of(...), $this->keys);
    }

    public function byFingerprint(string $fingerprint): ?string
    {
        return array_find($this->keys, static fn (string $key): bool => KeyFingerprint::of($key) === $fingerprint);
    }

    /** @return list<array{fingerprint: string, hmac_key: string}> what the socket-less one-off checks the HMAC with */
    public function hmacKeys(): array
    {
        return array_map(static fn (string $key): array => [
            'fingerprint' => KeyFingerprint::of($key),
            'hmac_key' => 'base64:'.base64_encode(hash_hkdf('sha256', (string) base64_decode(substr($key, 7), true), 32, ServiceEnvironments::BACKUP_HMAC_INFO)),
        ], $this->keys);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['keys' => '[redacted]'];
    }

    /** @return list<string> */
    private static function fromEnvLine(string $name, #[SensitiveParameter] string $value): array
    {
        if (! in_array($name, ['APP_KEY', 'APP_PREVIOUS_KEYS'], true)) {
            return []; // other lines of an env file are not keys
        }
        $keys = array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $key): bool => $key !== ''));
        foreach ($keys as $key) {
            if (! self::valid($key)) {
                throw self::invalid();
            }
        }

        return $keys;
    }

    private static function invalid(): InvalidInput
    {
        return new InvalidInput(
            'The key file holds no valid APP key (expected `base64:` and 32 bytes per line, or APP_KEY=/APP_PREVIOUS_KEYS= lines).',
            'invalid_key_file',
            'Pass the old instance\'s env/app.env or a file with its APP_KEY.',
        );
    }
}
