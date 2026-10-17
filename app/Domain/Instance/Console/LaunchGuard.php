<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;

/**
 * Checks every `fsa` command started by the shim or install.sh makes before it touches anything (KTD2, ADR 0070):
 *
 * - The launcher wrote a nonce file into the instance directory and every writable mount. If the container
 *   cannot see it, the daemon mounted something else (Docker Desktop or Colima without file sharing for that
 *   path): exit 3 with a file-sharing hint, before a write could land inside the VM instead of on the host.
 * - A file-path flag (FilePathFlags) must point into the instance directory or a directory the launcher
 *   mounted (writable for outputs), else exit 2.
 *
 * A command not started by a launcher (no FSA_NONCE, e.g. the detached supervisor) skips both checks.
 */
final class LaunchGuard
{
    public static function assertVisible(HostFacts $facts, string $instancePath): void
    {
        if ($facts->nonce === null) {
            return;
        }

        foreach ([$instancePath, ...$facts->writableMounts()] as $directory) {
            if (! is_file($directory.'/'.$facts->nonce)) {
                throw new InstanceException(
                    "Docker does not show {$directory} to the fsa container: the directory is not shared with the Docker engine.",
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
                    "--{$name} points outside every directory the fsa container can see: {$value}",
                    'path_not_mounted',
                    'Pass an absolute path through the instance\'s fsa command or install.sh; they mount its directory.',
                );
            }
        }
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
