<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;

/**
 * Checks every `fieldset` command started by the shim or install.sh makes before it touches anything (KTD2, ADR 0070):
 *
 * - The launcher wrote a nonce file into the instance directory and every writable mount. If the container
 *   cannot see it, the daemon mounted something else (Docker Desktop or Colima without file sharing for that
 *   path): exit 3 with a file-sharing hint, before a write could land inside the VM instead of on the host.
 * - No directory the launcher mounted for a file-path flag (FIELDSET_MOUNTS) may be reserved: one that equals,
 *   contains or lies inside a system path of the image or a directory other users can write to (RESERVED), or
 *   is / or /home. The launchers refuse these first (fieldset_reserved_path in the shim, install.sh); this repeats it
 *   in the container, before any Docker call, for an older launcher. The instance directory is mounted at its own
 *   path and is the owner's alone (0700); the launchers check it, and a test temp directory may be one.
 * - A file-path flag (FilePathFlags) must point into the instance directory or a directory the launcher
 *   mounted (writable for outputs), else exit 2.
 *
 * A command not started by a launcher (no FIELDSET_NONCE, e.g. the detached supervisor) skips all checks.
 */
final class LaunchGuard
{
    /**
     * Mounts may not equal, contain or lie inside these (nor be / or /home): they would cover the ops image's own
     * files, or hand the container a directory other local users can write to. Same list as the launchers.
     */
    public const array RESERVED = ['/tmp', '/var', '/usr', '/etc', '/run', '/proc', '/sys', '/dev', '/bin', '/sbin', '/lib', '/lib64', '/root'];

    /** Reserved only as exactly this path. */
    public const array RESERVED_EXACT = ['/', '/home'];

    public static function assertVisible(HostFacts $facts, string $instancePath): void
    {
        if ($facts->nonce === null) {
            return;
        }

        self::assertNoReservedMount($facts);

        foreach ([$instancePath, ...$facts->writableMounts()] as $directory) {
            if (! is_file($directory.'/'.$facts->nonce)) {
                throw new InstanceException(
                    "Docker does not show {$directory} to the fieldset container: the directory is not shared with the Docker engine.",
                    ExitCode::PreflightFailed,
                    'path_not_shared',
                    'Share the directory with Docker (Docker Desktop: Settings > Resources > File sharing; Colima: mounts in colima.yaml) or use a path under your home directory, then run the command again.',
                    false,
                    ['path' => $directory],
                );
            }
        }
    }

    /** @param  array<string, mixed>  $options  parsed command options by name */
    public static function assertFilePaths(HostFacts $facts, string $instancePath, array $options): void
    {
        if (! $facts->launched()) {
            return;
        }

        foreach ($options as $name => $value) {
            $kind = FilePathFlags::classify((string) $name);
            if ($kind === null || ! is_string($value) || $value === '') {
                continue;
            }
            if (! self::mounted($facts, $instancePath, $value, $kind === FilePathFlags::OUTPUT)) {
                throw new InvalidInput(
                    "--{$name} points outside every directory the fieldset container can see: {$value}",
                    'path_not_mounted',
                    'Pass an absolute path through the instance\'s fieldset command or install.sh; they mount its directory.',
                );
            }
        }
    }

    /** Whether a mount at this absolute path is reserved (lexically normalized first, like the launchers). */
    public static function reserved(string $path): bool
    {
        $path = self::normalize($path);
        if (in_array($path, self::RESERVED_EXACT, true)) {
            return true;
        }

        return array_any(self::RESERVED, static fn (string $root): bool => str_starts_with($path.'/', $root.'/') || str_starts_with($root.'/', $path.'/'));
    }

    private static function assertNoReservedMount(HostFacts $facts): void
    {
        foreach (array_keys($facts->mounts) as $directory) {
            if (self::reserved($directory)) {
                throw new InvalidInput(
                    "The launcher mounted {$directory}, which would cover files of the fieldset container or a directory other users can write to.",
                    'path_reserved',
                    'Pass file paths under your home directory or in the instance directory, and update the instance\'s fieldset (install.sh).',
                );
            }
        }
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }

    private static function mounted(HostFacts $facts, string $instancePath, string $path, bool $write): bool
    {
        if (! str_starts_with($path, '/')) {
            return false;
        }

        $parent = dirname($path);
        $roots = [$instancePath, ...array_keys(array_filter($facts->mounts, static fn (bool $writable): bool => $writable || ! $write))];

        return array_any($roots, static fn (string $root): bool => $parent === $root || str_starts_with($parent.'/', rtrim($root, '/').'/'));
    }
}
