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
use RuntimeException;
use SensitiveParameter;

/**
 * Finds the backup a restore reads and prepares it (plan U17, ADR 0085). `fsa restore <backup>` takes a name in
 * `backups/` or a path inside the instance directory (the only paths the ops container is sure to see); `fsa install
 * --from-backup` takes any path the launcher mounted. The prepare step runs `fsa restore-prepare` from the app image with
 * `--network none` and no socket, the backup and the identity mounted read-only and only a fresh scratch directory
 * (`staging/restore-<id>`, 0750, inside the private staging directory, never in `backups/`, which people sync off the
 * host: it holds the decrypted set, M12 gate S3) writable; the passphrase and the HMAC keys travel on its stdin.
 */
final readonly class RestoreSource
{
    public const string ROLE = 'restore-prepare';

    public const string SCRATCH_PREFIX = 'restore-';

    /** Where 2.0 pre-releases put scratch directories (`backups/.restore-<id>`): still found and removed. */
    public const string LEGACY_SCRATCH_PREFIX = '.restore-';

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
        // The ops container sees only the instance directory: a path outside it is reported as such before its
        // existence is checked, since from in here it never exists (R51 F1).
        if (! $mountedElsewhere && ! str_starts_with($this->normalized($path).'/', $this->directory->root.'/')) {
            throw new InvalidInput("{$path} lies outside the instance directory, which is all fsa restore can see.", 'backup_not_visible', 'Copy the backup into backups/ and run `fsa restore <name>`.');
        }
        if (str_contains($path, '/../') || str_ends_with($path, '/..') || ! file_exists($path)) {
            throw new InvalidInput("There is no backup at {$path}.", 'backup_not_found', 'Run `fsa status` for the newest backups; a backup from elsewhere goes into backups/ first.');
        }

        return $path;
    }

    /** The absolute path with `.` and `..` segments resolved lexically (links are not followed). */
    private function normalized(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return '/'.implode('/', $segments);
    }

    /** The scratch directory of one restore, relative to the instance directory. */
    public static function scratchPath(string $id): string
    {
        return InstanceDirectory::STAGING_DIR.'/'.self::SCRATCH_PREFIX.$id;
    }

    /** A fresh scratch directory for one restore (`staging/` itself is 0700). */
    public function scratch(string $id): string
    {
        $relative = self::scratchPath($id);
        if ($this->directory->exists($relative)) {
            $this->directory->files()->delete($relative);
        }
        $this->directory->ensureDirectory(InstanceDirectory::STAGING_DIR);

        return $this->directory->ensureDirectory($relative, 0750);
    }

    /** @throws RuntimeException naming what stays (it may hold the decrypted or copied set) */
    public function discard(string $id): void
    {
        $left = [
            ...$this->directory->files()->delete(self::scratchPath($id)),
            ...$this->directory->files()->delete(InstanceDirectory::BACKUPS_DIR.'/'.self::LEGACY_SCRATCH_PREFIX.$id),
        ];
        if ($left !== []) {
            throw new RuntimeException('Cannot remove '.implode(', ', $left).'.');
        }
    }

    /**
     * Runs the socket-less prepare step and reads its verdict.
     *
     * @param  array{image: string, uuid: string, user: string, adopt_backup?: array{name: string, sums: string}|null}  $runAs
     *
     * @throws InstanceException exit 2 for an unusable backup (unsafe, encrypted without identity, wrong passphrase)
     */
    public function prepare(array $runAs, string $source, string $scratch, AppKeys $keys, ?string $identityFile, #[SensitiveParameter] ?string $passphrase): PreparedBackup
    {
        $mount = is_dir($source) ? $source : dirname($source);
        $volumes = [$mount.':'.$mount.':ro', $scratch.':'.$scratch];
        if ($identityFile !== null) {
            // Docker creates a missing bind source as a root-owned directory on the host: check before mounting.
            if (! is_file($identityFile) || ! is_readable($identityFile)) {
                throw new InvalidInput("The identity file {$identityFile} is missing, unreadable or not a regular file.", 'identity_unreadable', 'Pass the path of the identity file that --identity-out of `fsa backup --encrypt` or `fsa config set backup-encryption on` wrote; nothing was changed.');
            }
            $volumes[] = $identityFile.':'.$identityFile.':ro'; // the file alone, never its directory (often $HOME)
        }
        $input = json_encode(['passphrase' => $passphrase, 'keys' => $keys->hmacKeys()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $outcome = OneOffResult::fromOutcome($this->docker->runContainer(new ContainerSpec(
            image: $runAs['image'],
            command: ['fsa', 'restore-prepare', '--source='.$source, '--scratch='.$scratch, ...($identityFile === null ? [] : ['--identity-file='.$identityFile]), '--json'],
            labels: Labels::forInstance($runAs['uuid'], self::ROLE),
            volumes: array_values(array_unique($volumes)),
            network: 'none',
            user: $runAs['user'],
        )->hardened(), $input, self::TIMEOUT));
        if (! $outcome->ok) {
            throw $this->failure($outcome);
        }

        return self::confined(PreparedBackup::fromArray($outcome->data), $source, $scratch);
    }

    /**
     * The paths the prepare container reports are mounted into later containers by the process that holds the socket:
     * they must lie in the scratch directory, whatever a compromised prepare step claims, and be no link (Docker resolves
     * a bind source on the host). The backup itself is never one of them: later steps read only the set the prepare step
     * verified there, which nobody else can write (M12 gate).
     *
     * @throws InstanceException exit 4 `prepare_failed`
     */
    public static function confined(PreparedBackup $backup, string $source, string $scratch): PreparedBackup
    {
        $allowed = [
            'set' => [$scratch.'/'.BackupPreparer::SET_DIR],
            'archive' => [$scratch.'/'.BackupPreparer::SET_DIR.'/storage.tar.gz'],
            'listing' => [$scratch.'/'.BackupPreparer::MEMBERS],
            'list' => [$scratch.'/'.BackupPreparer::RESTORE_LIST],
        ];
        $paths = ['set' => $backup->set, 'archive' => $backup->storage['archive'] ?? null, 'listing' => $backup->storage['listing'] ?? null, 'list' => $backup->dump['list'] ?? null];
        foreach ($paths as $key => $path) {
            if ($path !== null && ! in_array($path, $allowed[$key], true)) {
                $what = $path === $source || str_starts_with($path, $source.'/') ? 'the backup itself instead of its verified copy, a path' : 'a path';
                throw new InstanceException("The prepare step reported {$what} outside the backup's scratch directory ({$key}).", ExitCode::HealthCheckFailed, 'prepare_failed', 'Nothing was changed; run the command again, and report it if it repeats.');
            }
        }
        self::assertNoLinks($backup->set, array_values(array_filter([$paths['archive'], $paths['listing'], $paths['list']], is_string(...))));

        return $backup;
    }

    /**
     * @param  list<string>  $files
     *
     * @throws InstanceException exit 4 `prepare_failed`
     */
    private static function assertNoLinks(string $set, array $files): void
    {
        $names = array_unique([...BackupSet::FILES, ...BackupSet::LEGACY_FILES]);
        $linked = array_filter([$set, ...$files, ...array_map(static fn (string $name): string => $set.'/'.$name, $names)], is_link(...));
        if ($linked !== [] || ! is_dir($set)) {
            throw new InstanceException('The prepare step left a link (or no directory) where the verified set must be: '.implode(', ', $linked ?: [$set]).'.', ExitCode::HealthCheckFailed, 'prepare_failed', 'Nothing was changed; run the command again, and report it if it repeats.');
        }
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
