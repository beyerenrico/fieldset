<?php

declare(strict_types=1);

namespace App\Domain\Instance\Ownership;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;

/**
 * Gives the instance files to a new in-container owner (2.1.1 U4, KTD12). The ops user may be the host user, who cannot
 * take files from root, so a root container of the instance's own verified image does it, without a network, with only
 * the capabilities of an owner copy and a read-only root file system. It mounts the instance directory alone, at its own
 * path (Docker resolves the host path), and `find`:
 *
 * - stays on that file system (`-xdev`) and never follows a symlink (find's default `-P`);
 * - skips symlinks and files with a second hard link (whose other name may lie outside the instance);
 * - changes owners only, without dereferencing (`chown -h`), and only of entries the new owner does not own yet; modes
 *   stay (the capability FSETID keeps set-ID bits).
 *
 * Docker volumes are not mounted, so they are never touched. Afterwards the secrets (the env files) must still be 0600.
 */
final readonly class OwnerFiles
{
    public const string ROLE = 'owner';

    /** A large backups/ folder takes a while on a slow disk. */
    public const float TIMEOUT = 1800.0;

    public function __construct(private DockerGateway $docker) {}

    /** @return list<string> the root container's `find` arguments */
    public static function command(string $root, int $uid, int $gid): array
    {
        return [$root, '-xdev', '!', '-type', 'l', '(', '-type', 'd', '-o', '-links', '1', ')', '!', '-uid', (string) $uid,
            '-print', '-exec', 'chown', '-h', "{$uid}:{$gid}", '{}', '+'];
    }

    public static function spec(InstanceState $state, string $root, OwnerChange $change): ContainerSpec
    {
        $image = $state->digests()['app'] ?? throw new InvalidState('The instance state names no app image.');

        return new ContainerSpec(
            image: $image, command: self::command($root, $change->to, $change->group), labels: Labels::forInstance($state->uuid(), self::ROLE),
            volumes: [$root.':'.$root], network: 'none', user: '0:0', entrypoint: 'find',
        )->hardened(ContainerSpec::OWNER_COPY);
    }

    /**
     * @return int how many entries got the new owner
     *
     * @throws InstanceException exit 4 `owner_repair_failed`
     */
    public function give(InstanceState $state, string $root, OwnerChange $change): int
    {
        $outcome = $this->docker->runContainer(self::spec($state, $root, $change), null, self::TIMEOUT);
        if (! $outcome->successful()) {
            throw new InstanceException(
                "Could not give the instance files to UID {$change->to}: ".($outcome->stderrTail(3) ?: "exit {$outcome->exitCode}"),
                ExitCode::HealthCheckFailed,
                'owner_repair_failed',
                'The owner saved with the instance is unchanged; fix the cause, then run `fieldset repair-ownership` again (files already changed stay with the new owner).',
                true,
                changed: $outcome->stdout !== '', // find names each entry before chown takes it
            );
        }

        return count(array_filter(explode("\n", $outcome->stdout), static fn (string $line): bool => $line !== ''));
    }

    /** @throws InstanceException exit 4 `secrets_exposed` when an env file is no longer 0600 */
    public static function assertSecretsPrivate(InstanceDirectory $directory): void
    {
        $env = new EnvFiles($directory);
        foreach ($env->services() as $service) {
            $path = $env->path($service);
            clearstatcache(true, $path);
            $mode = @fileperms($path);
            if ($mode === false || ($mode & 0777) !== 0600) {
                throw new InstanceException(
                    sprintf('%s is not mode 0600 after the owner changed (%s).', $path, $mode === false ? 'unreadable' : sprintf('%04o', $mode & 0777)),
                    ExitCode::HealthCheckFailed,
                    'secrets_exposed',
                    "Run chmod 600 {$path} as the instance owner, then run `fieldset repair-ownership` again.",
                );
            }
        }
    }
}
