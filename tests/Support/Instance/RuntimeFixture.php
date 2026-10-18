<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use Carbon\CarbonImmutable;

/**
 * Runtime directories for the app-side runtime tests (U10): runtime.json as `fsa` writes it, replaced by rename
 * (a new inode on every write), wired into config('instance.runtime_path'). Call cleanup() in afterEach.
 */
final class RuntimeFixture
{
    /** cloudflared's fixed address on its network (the tunnel peer). */
    public const string PEER = '172.30.0.10';

    public const string TUNNEL = 'quiet-river-1234.trycloudflare.com';

    /** A 64-hex smoke token as RuntimeWriter::raiseGate() generates it. */
    public const string TOKEN = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    /** @var list<string> */
    private static array $created = [];

    /** @param  array<string, mixed>  $overrides */
    public static function install(array $overrides = []): string
    {
        $directory = self::directory();
        self::write($directory, self::document($overrides));

        return $directory;
    }

    /** An empty runtime directory (no runtime.json yet), wired into the config. */
    public static function directory(): string
    {
        $directory = sys_get_temp_dir().'/fsa-runtime-'.bin2hex(random_bytes(6));
        mkdir($directory, 0755);
        self::$created[] = $directory;
        config(['instance.runtime_path' => $directory]);

        return $directory;
    }

    /**
     * @param  array<string, mixed>  $overrides  merged recursively into a valid local-mode document
     * @return array<string, mixed>
     */
    public static function document(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0',
            'instance_url' => 'http://localhost:8080',
            'local_url' => 'http://localhost:8080',
            'extra_hosts' => [],
            'tunnel' => ['hostname' => null, 'expires_at' => null, 'peer' => null, 'admin' => false],
            'gate' => ['raised' => false, 'operation' => null, 'since' => null, 'smoke_token' => null],
        ], $overrides);
    }

    /** @return array<string, mixed> domain mode: an https instance URL behind the edge, no local URL */
    public static function domain(string $host = 'forms.example'): array
    {
        return ['instance_url' => 'https://'.$host, 'local_url' => null];
    }

    /** @return array<string, mixed> an active (or, with a past offset, expired) quick tunnel */
    public static function tunnel(string $expires = '+2 hours', bool $admin = false): array
    {
        return ['tunnel' => [
            'hostname' => self::TUNNEL,
            'expires_at' => CarbonImmutable::now('UTC')->modify($expires)->toIso8601ZuluString(),
            'peer' => self::PEER,
            'admin' => $admin,
        ]];
    }

    /** @return array<string, mixed> */
    public static function raisedGate(?string $token = self::TOKEN): array
    {
        return ['gate' => ['raised' => true, 'operation' => 'upgrade', 'since' => '2026-10-18T03:30:00Z', 'smoke_token' => $token]];
    }

    /** @param  array<string, mixed>|string  $document */
    public static function write(string $directory, array|string $document): void
    {
        $temporary = $directory.'/.runtime.json.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, is_string($document) ? $document : json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($temporary, 0644);
        rename($temporary, $directory.'/runtime.json');
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $directory) {
            if (is_dir($directory) && ! is_link($directory)) {
                exec('rm -rf '.escapeshellarg($directory));
            }
        }
        self::$created = [];
    }
}
