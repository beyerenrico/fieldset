<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\State\EnvFiles;
use SensitiveParameter;

/**
 * Keyed digests of personal install inputs (owner e-mail and name), so the state history and archived journals
 * can still answer "same flags as the installation?" (R13, AE3) without keeping the values, which would never be
 * pruned or erased there.
 * The key is derived from the instance's APP_KEY, which never leaves `env/app.env`; earlier keys in
 * APP_PREVIOUS_KEYS still match, so a key rotation does not turn a repeated install into a conflict.
 */
final readonly class RecordDigest
{
    public const string PREFIX = 'hmac-sha256:';

    private const string CONTEXT = 'fsa:install-record:v1';

    /** @param  list<string>  $keys  derived keys, current first */
    private function __construct(#[SensitiveParameter] private array $keys) {}

    /** Null when the instance has no APP_KEY yet (before the secrets phase): nothing can be digested then. */
    public static function fromEnvFiles(EnvFiles $env): ?self
    {
        $app = $env->read('app');
        $keys = array_values(array_filter([$app['APP_KEY'] ?? '', ...explode(',', $app['APP_PREVIOUS_KEYS'] ?? '')], static fn (string $key): bool => trim($key) !== ''));

        return $keys === [] ? null : new self(array_map(static fn (string $key): string => hash_hmac('sha256', self::CONTEXT, trim($key), true), $keys));
    }

    public function digest(#[SensitiveParameter] string $value): string
    {
        return self::PREFIX.hash_hmac('sha256', $value, $this->keys[0]);
    }

    /** Whether a recorded value (a digest, or a plain value written by an earlier build) stands for `$value`. */
    public function matches(?string $recorded, #[SensitiveParameter] string $value): bool
    {
        if ($recorded === null || ! str_starts_with($recorded, self::PREFIX)) {
            return $recorded === $value;
        }

        return array_any($this->keys, static fn (string $key): bool => hash_equals($recorded, self::PREFIX.hash_hmac('sha256', $value, $key)));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['keys' => '[redacted]'];
    }
}
