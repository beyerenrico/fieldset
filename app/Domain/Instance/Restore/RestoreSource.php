<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use SensitiveParameter;

/**
 * Finds the backup a restore reads and prepares it (plan U17, ADR 0085). `fsa restore <backup>` takes a name in
 * `backups/` or a path inside the instance directory (the only paths the ops container is sure to see); `fsa install
 * --from-backup` takes any path the launcher mounted. The prepare step runs `fsa restore-prepare` from the app image with
 * `--network none` and no socket, the backup and the identity mounted read-only and only a fresh scratch directory
 * (`backups/.restore-<id>`, 0750) writable; the passphrase and the HMAC keys travel on its stdin.
 */
final readonly class RestoreSource
{
    public const string ROLE = 'restore-prepare';

    public const string SCRATCH_PREFIX = '.restore-';

    private const float TIMEOUT = 21600.0;

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /** @throws InvalidInput exit 2 for a backup that does not exist or lies where the ops container cannot see it */
    public function resolve(string $argument, bool $mountedElsewhere = false): string
    {
        $argument = rtrim(trim($argument), '/');
        $path = match (true) {
            $argument === '' => throw new InvalidInput('Name the backup to restore.', 'backup_required', 'Run `fsa status` for the newest backups, then `fsa restore <name>`.'),
            str_starts_with($argument, '/') => $argument,
            ! str_contains($argument, '/') => $this->directory->root.'/'.InstanceDirectory::BACKUPS_DIR.'/'.$argument,
            default => $this->directory->root.'/'.$argument,
        };
        if (str_contains($path, '/../') || str_ends_with($path, '/..') || ! file_exists($path)) {
            throw new InvalidInput("There is no backup at {$path}.", 'backup_not_found', 'Run `fsa status` for the newest backups; a backup from elsewhere goes into backups/ first.');
        }
        if (! $mountedElsewhere && ! str_starts_with($path.'/', $this->directory->root.'/')) {
            throw new InvalidInput("{$path} lies outside the instance directory, which is all fsa restore can see.", 'backup_not_visible', 'Copy the backup into backups/ and run `fsa restore <name>`.');
        }

        return $path;
    }

    /** A fresh scratch directory for one restore. */
    public function scratch(string $id): string
    {
        $relative = InstanceDirectory::BACKUPS_DIR.'/'.self::SCRATCH_PREFIX.$id;
        if ($this->directory->exists($relative)) {
            $this->directory->files()->delete($relative);
        }

        return $this->directory->ensureDirectory($relative, 0750);
    }

    public function discard(string $id): void
    {
        $this->directory->files()->delete(InstanceDirectory::BACKUPS_DIR.'/'.self::SCRATCH_PREFIX.$id);
    }

    /**
     * Runs the socket-less prepare step and reads its verdict.
     *
     * @param  array{image: string, uuid: string, user: string}  $runAs
     *
     * @throws InstanceException exit 2 for an unusable backup (unsafe, encrypted without identity, wrong passphrase)
     */
    public function prepare(array $runAs, string $source, string $scratch, AppKeys $keys, ?string $identityFile, #[SensitiveParameter] ?string $passphrase): PreparedBackup
    {
        $mount = is_dir($source) ? $source : dirname($source);
        $volumes = [$mount.':'.$mount.':ro', $scratch.':'.$scratch];
        if ($identityFile !== null) {
            $volumes[] = dirname($identityFile).':'.dirname($identityFile).':ro';
        }
        $input = json_encode(['passphrase' => $passphrase, 'keys' => $keys->hmacKeys()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $outcome = OneOffResult::fromOutcome($this->docker->runContainer(new ContainerSpec(
            image: $runAs['image'],
            command: ['fsa', 'restore-prepare', '--source='.$source, '--scratch='.$scratch, ...($identityFile === null ? [] : ['--identity-file='.$identityFile]), '--json'],
            labels: Labels::forInstance($runAs['uuid'], self::ROLE),
            volumes: array_values(array_unique($volumes)),
            network: 'none',
            user: $runAs['user'],
        ), $input, self::TIMEOUT));
        if (! $outcome->ok) {
            throw $this->failure($outcome);
        }

        return PreparedBackup::fromArray($outcome->data);
    }

    private function failure(OneOffResult $outcome): InstanceException
    {
        $hint = match ($outcome->errorCode()) {
            'identity_required' => 'Pass the identity file with --identity-file and its passphrase with --passphrase-file or --passphrase-stdin.',
            'decrypt_failed' => 'Check the identity file and its passphrase; nothing was changed.',
            'backup_unsafe' => 'Nothing was written; use a backup written by fsa or by scripts/backup.sh of this app.',
            default => $outcome->errorHint() ?? 'Read the output; nothing was changed.',
        };
        $exit = in_array($outcome->errorCode(), ['prepare_failed', 'one_off_failed', 'one_off_timeout'], true) ? ExitCode::HealthCheckFailed : ExitCode::InvalidInput;

        return new InstanceException($outcome->errorMessage(), $exit, $outcome->errorCode(), $hint, $exit === ExitCode::HealthCheckFailed, ['output' => $outcome->output]);
    }
}
