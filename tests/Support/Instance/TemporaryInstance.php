<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/** Throwaway instance directories for unit tests, removed by `cleanup()` (call it in afterEach). */
final class TemporaryInstance
{
    /** @var list<string> */
    private static array $created = [];

    public static function path(): string
    {
        $path = sys_get_temp_dir().'/fieldset-test-'.bin2hex(random_bytes(6));
        self::$created[] = $path;

        return $path;
    }

    public static function directory(): InstanceDirectory
    {
        return InstanceDirectory::initialize(self::path());
    }

    public static function withState(?InstanceDirectory $directory = null): InstanceDirectory
    {
        $directory ??= self::directory();
        $directory->saveState(InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $directory->root, 501, 20, 501));

        return $directory;
    }

    public static function holder(string $container = 'a', string $startedAt = '2026-10-17T10:00:00.000000001Z', string $owner = 'fieldset'): LockHolder
    {
        return new LockHolder($owner, str_repeat($container, 64), $startedAt, 4242, 'ops-host', 501);
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            if (is_dir($path) && ! is_link($path)) {
                exec('chmod -R u+rwx '.escapeshellarg($path).' && rm -rf '.escapeshellarg($path));
            }
        }
        self::$created = [];
    }
}
