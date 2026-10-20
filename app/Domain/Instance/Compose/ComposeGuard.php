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
 * - Nothing reaches past the containers (review U18 S2, also applied to a target's staged files): bind mounts only of
 *   paths inside the instance directory (`./…`, no `..`), no volume backed by a host path (driver options), no
 *   capability beyond those the services need, no devices, no security option but `no-new-privileges`, no host or shared
 *   namespaces (pid, ipc, uts, userns, cgroup, network_mode), nothing privileged, no build or extends, env files only
 *   from `./env/`, no included files, secrets or configs.
 */
final class ComposeGuard
{
    private const string UNSAFE = '/[\x00-\x1F\x7F$]/';

    private const array SOCKETS = ['docker.sock', '/var/run/docker', '/run/docker'];

    /** The capabilities services add back after `cap_drop: [ALL]` (backup: file owners; edge: ports below 1024). */
    private const array CAPABILITIES = ['CHOWN', 'DAC_OVERRIDE', 'DAC_READ_SEARCH', 'FOWNER', 'NET_BIND_SERVICE'];

    private const array NAMESPACES = ['pid', 'ipc', 'uts', 'userns_mode', 'cgroup', 'cgroup_parent', 'network_mode'];

    private const array FORBIDDEN = ['devices', 'device_cgroup_rules', 'build', 'extends', 'volumes_from'];

    private const string ENV_FILE = '#^\./env/[a-z][a-z0-9-]{0,62}\.env$#D';

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, array<string, string>>  $environments
     */
    public static function assertSafe(array $document, array $environments): void
    {
        self::assertStrings($document, 'compose');
        foreach (['include', 'secrets', 'configs'] as $key) {
            if (array_key_exists($key, $document)) {
                self::fail("The compose file would use [{$key}]; fsa renders everything into one file.");
            }
        }
        foreach ($document['services'] ?? [] as $name => $service) {
            self::assertService((string) $name, is_array($service) ? $service : [], $environments[$name] ?? []);
            self::assertContained((string) $name, is_array($service) ? $service : []);
        }
        foreach (['volumes', 'networks'] as $kind) {
            foreach ($document[$kind] ?? [] as $name => $definition) {
                self::assertLabels(is_array($definition) ? ($definition['labels'] ?? []) : [], "{$kind}.{$name}");
                if ($kind === 'volumes' && is_array($definition) && (isset($definition['driver_opts']) || ($definition['driver'] ?? 'local') !== 'local')) {
                    self::fail("The volume [{$name}] would set a driver or driver options, which can mount a host path.");
                }
            }
        }
    }

    /** @param  array<array-key, mixed>  $service */
    private static function assertContained(string $name, array $service): void
    {
        foreach ($service['volumes'] ?? [] as $volume) {
            self::assertMount($name, $volume);
        }
        $added = array_diff(array_map(strval(...), (array) ($service['cap_add'] ?? [])), self::CAPABILITIES);
        if ($added !== []) {
            self::fail("The service [{$name}] would add the capability ".implode(', ', $added).'.');
        }
        foreach (self::FORBIDDEN as $key) {
            if (array_key_exists($key, $service)) {
                self::fail("The service [{$name}] would set [{$key}] (devices, build, extends and volumes_from are never rendered).");
            }
        }
        $options = array_diff(array_map(strval(...), (array) ($service['security_opt'] ?? [])), ['no-new-privileges:true', 'no-new-privileges', 'no-new-privileges=true']);
        if ($options !== []) {
            self::fail("The service [{$name}] would set the security option ".implode(', ', $options).'.');
        }
        foreach (self::NAMESPACES as $key) {
            if (array_key_exists($key, $service)) {
                self::fail("The service [{$name}] would set [{$key}]: it shares a namespace with the host or another container.");
            }
        }
        if (($service['privileged'] ?? false) !== false) {
            self::fail("The service [{$name}] would run privileged.");
        }
        foreach ((array) ($service['env_file'] ?? []) as $file) {
            $path = is_array($file) ? (string) ($file['path'] ?? '') : (string) $file;
            if (preg_match(self::ENV_FILE, $path) !== 1) {
                self::fail("The service [{$name}] would read the env file {$path}; env files come only from ./env/.");
            }
        }
    }

    private static function assertMount(string $name, mixed $volume): void
    {
        if (is_array($volume)) {
            $type = (string) ($volume['type'] ?? 'volume');
            $source = (string) ($volume['source'] ?? '');
            if ($type === 'bind' && ! self::inside($source)) {
                self::fail("The service [{$name}] would bind-mount {$source}, outside the instance directory.");
            }
            if (! in_array($type, ['bind', 'volume', 'tmpfs'], true)) {
                self::fail("The service [{$name}] would mount a [{$type}].");
            }

            return;
        }
        $source = explode(':', (string) $volume)[0];
        $named = preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]*$#D', $source) === 1;
        if (! $named && ! self::inside($source)) {
            self::fail("The service [{$name}] would bind-mount {$source}, outside the instance directory.");
        }
    }

    /** A bind source inside the instance directory: `./…` with no `..` segment. */
    private static function inside(string $source): bool
    {
        return str_starts_with($source, './') && ! in_array('..', explode('/', $source), true);
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
