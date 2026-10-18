<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * Last checks on a rendered project before anything is written (KTD6, KTD25). A failure here is a renderer bug,
 * never user input (that is refused earlier by InputGrammar), so it exits 1.
 *
 * - No string may hold a control character or a `$`: Compose interpolates `$` in its file, and nothing the
 *   renderer emits needs it (secrets travel in env files, which are literal).
 * - No service mounts the Docker socket: only ops and supervisor containers hold it.
 * - No service sets RUN_MIGRATIONS: migrations run only in supervised one-offs.
 * - No label uses Compose's reserved `com.docker.compose` prefix.
 */
final class ComposeGuard
{
    private const string UNSAFE = '/[\x00-\x1F\x7F$]/';

    private const array SOCKETS = ['docker.sock', '/var/run/docker', '/run/docker'];

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, array<string, string>>  $environments
     */
    public static function assertSafe(array $document, array $environments): void
    {
        self::assertStrings($document, 'compose');
        foreach ($document['services'] ?? [] as $name => $service) {
            self::assertService((string) $name, is_array($service) ? $service : [], $environments[$name] ?? []);
        }
        foreach (['volumes', 'networks'] as $kind) {
            foreach ($document[$kind] ?? [] as $name => $definition) {
                self::assertLabels(is_array($definition) ? ($definition['labels'] ?? []) : [], "{$kind}.{$name}");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $service
     * @param  array<string, string>  $environment
     */
    private static function assertService(string $name, array $service, array $environment): void
    {
        foreach ($service['volumes'] ?? [] as $volume) {
            $source = is_array($volume) ? (string) ($volume['source'] ?? '') : explode(':', (string) $volume)[0];
            foreach (self::SOCKETS as $socket) {
                if (str_contains($source, $socket)) {
                    self::fail("The service [{$name}] would mount the Docker socket; only ops and supervisor containers may.");
                }
            }
        }
        $inline = is_array($service['environment'] ?? null) ? $service['environment'] : [];
        if (array_key_exists('RUN_MIGRATIONS', $environment) || array_key_exists('RUN_MIGRATIONS', $inline)) {
            self::fail("The service [{$name}] sets RUN_MIGRATIONS; migrations run only in supervised one-offs.");
        }
        self::assertLabels($service['labels'] ?? [], "services.{$name}");
    }

    private static function assertLabels(mixed $labels, string $resource): void
    {
        foreach (is_array($labels) ? array_keys($labels) : [] as $key) {
            if (str_starts_with((string) $key, 'com.docker.compose')) {
                self::fail("The label [{$key}] on {$resource} uses the prefix com.docker.compose, which Compose reserves.");
            }
        }
    }

    private static function assertStrings(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertStrings((string) $key, $path);
                self::assertStrings($item, $path.'.'.$key);
            }

            return;
        }
        if (is_string($value) && preg_match(self::UNSAFE, $value) === 1) {
            self::fail("The compose file would contain an unsafe value at {$path} (a control character or a \$ interpolation).");
        }
    }

    private static function fail(string $message): never
    {
        throw new InstanceException($message, ExitCode::InternalError, 'unsafe_compose');
    }
}
