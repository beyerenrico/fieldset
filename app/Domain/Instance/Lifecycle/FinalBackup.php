<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupRunner;
use App\Domain\Instance\Backup\ManualBackup;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\DockerUnavailable;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The last backup `fsa uninstall --keep-backups` (and an adopted instance's uninstall) writes before it removes anything
 * (§13.3 "back up before destroying", ADR 0073 as amended by the M12 gate): a backup of the 2.0 format, `final-<UTC>`,
 * written by the backup service's own entry point like `fsa backup` (HMAC, SHA256SUMS, encrypted when the instance has
 * recipients), so `fsa install --from-backup` and `fsa restore` read it.
 *
 * - The stack is quiesced first (the gate goes up; app, scheduler and the backup service stop, then the queue with its
 *   grace period), so no write is acknowledged after the snapshot; a failed backup brings back what ran (gate down).
 * - The APP keys: encrypted, they go into the set as `secrets.env` (with the SMTP account, like `--include-secrets`);
 *   plain, they go beside it as `backups/<name>.app-key.env` (0600, ADR 0073), which the result names in a warning.
 * - Without the database volume there is nothing to dump: only the keys are saved beside (to the recipients when
 *   encryption is on), for the backups `backups/` keeps; without an APP key either, nothing (null).
 *
 * Any failure is exit 4 (`final_backup_failed`): the caller then removes nothing.
 */
final readonly class FinalBackup
{
    public const string KIND = 'final';

    public const string KEY_SUFFIX = '.app-key.env';

    public const string GATE_OPERATION = 'uninstall';

    private const array WRITERS = [AppRoles::APP, AppRoles::SCHEDULER, BackupService::NAME];

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
        private BackupRunner $runner,
        private AgeCli $age,
    ) {}

    /**
     * @param  list<DockerResource>  $volumes  the instance's labelled volumes
     * @return array{dir: string|null, backup: string|null, encrypted: bool, includes_secrets: bool, key_file: string|null, missing_files: int}|null
     *
     * @throws InstanceException exit 4; nothing was removed, a quiesced stack runs again
     */
    public function write(?InstanceState $state, array $volumes, LockHandle $lock): ?array
    {
        $hasKey = (new EnvFiles($this->directory)->read('app')['APP_KEY'] ?? '') !== '';
        $database = $this->find($volumes, PostgresService::VOLUME);
        if (! $hasKey && ! $database) {
            return null;
        }

        try {
            if (! $state instanceof InstanceState) {
                throw new InstanceException('The instance state is unreadable, so its services cannot be run.');
            }

            return $database ? $this->backup($state, $lock) : $this->keysOnly($state);
        } catch (DockerUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            $detail = $e instanceof DockerCommandFailed ? trim($e->getMessage().' '.$e->outcome->stderrTail(3)) : $e->getMessage();

            throw new InstanceException(
                'The final backup failed, so nothing was removed: '.$detail,
                ExitCode::HealthCheckFailed,
                'final_backup_failed',
                'Fix the cause (`fsa status`, `fsa logs backup`, free disk space), then run the same `fsa uninstall` again; or run `fsa uninstall --no-backup` to delete everything, backups/ included, without a final backup.',
                true,
                $e instanceof InstanceException ? $e->data : [],
                $e,
            );
        }
    }

    /** @return array{dir: string, backup: string, encrypted: bool, includes_secrets: bool, key_file: string|null, missing_files: int} */
    private function backup(InstanceState $state, LockHandle $lock): array
    {
        $project = ComposeProject::forInstance($state, $this->directory);
        $encrypted = $state->recipients() !== [];
        $running = $this->quiesce($state, $project);

        try {
            $outcome = $this->runner->backup($project, self::KIND, $lock->record->lockId, $encrypted ? ManualBackup::secrets($this->directory) : null);
            if (! $outcome->ok) {
                throw new InstanceException($outcome->errorMessage(), ExitCode::HealthCheckFailed, 'final_backup_failed', null, true, ['reason' => $outcome->errorCode()]);
            }
            $name = (string) ($outcome->data['backup'] ?? '');
            $keyFile = $encrypted ? null : $this->saveKeys(InstanceDirectory::BACKUPS_DIR.'/'.$name.self::KEY_SUFFIX);
        } catch (Throwable $e) {
            $this->reopen($state, $project, $running);

            throw $e;
        }
        $result = is_array($outcome->data['result'] ?? null) ? $outcome->data['result'] : [];

        return [
            'dir' => InstanceDirectory::BACKUPS_DIR.'/'.$name, 'backup' => $name, 'encrypted' => $encrypted, 'includes_secrets' => $encrypted,
            'key_file' => $keyFile, 'missing_files' => is_int($outcome->data['missing_files'] ?? null) ? $outcome->data['missing_files'] : 0,
            ...(is_array($result['warnings'] ?? null) && $result['warnings'] !== [] ? ['warnings' => $result['warnings']] : []),
        ];
    }

    /** @return array{dir: null, backup: null, encrypted: bool, includes_secrets: false, key_file: string, missing_files: int} */
    private function keysOnly(InstanceState $state): array
    {
        $relative = InstanceDirectory::BACKUPS_DIR.'/'.self::KIND.'-'.CarbonImmutable::now('UTC')->format('Ymd\THis\Z').self::KEY_SUFFIX;
        $encrypted = $state->recipients() !== [];

        return ['dir' => null, 'backup' => null, 'encrypted' => $encrypted, 'includes_secrets' => false, 'key_file' => $this->saveKeys($relative, $encrypted ? $state->recipients() : []), 'missing_files' => 0];
    }

    /**
     * APP_KEY and APP_PREVIOUS_KEYS from env/app.env, 0600; encrypted to `$recipients` (`<file>.age`) when given.
     *
     * @param  list<string>  $recipients
     * @return string the written file, relative to the instance directory
     */
    private function saveKeys(string $relative, array $recipients = []): string
    {
        $app = new EnvFiles($this->directory)->read('app');
        $keys = array_filter(['APP_KEY' => $app['APP_KEY'] ?? '', 'APP_PREVIOUS_KEYS' => $app['APP_PREVIOUS_KEYS'] ?? ''], static fn (string $value): bool => $value !== '');
        $header = '# The APP keys of the backups beside this file: they authenticate them (`--app-key-file`) and decrypt their encrypted columns. Keep it as safe as the backups.'."\n";
        $contents = (string) preg_replace('/\A#[^\n]*\n/', $header, EnvFiles::encode($keys));
        $this->directory->ensureDirectory(InstanceDirectory::BACKUPS_DIR);
        if ($recipients === []) {
            $this->directory->write($relative, $contents, 0600);

            return $relative;
        }
        $this->age->encryptToRecipients($contents, $recipients, $this->directory->path($relative.'.age'));
        @chmod($this->directory->path($relative.'.age'), 0600);

        return $relative.'.age';
    }

    /**
     * Gate up, then app, scheduler and the backup service, then the queue with its grace period (RestoreStack::quiesce);
     * Postgres keeps running for the dump.
     *
     * @return list<string> the services that ran
     */
    private function quiesce(InstanceState $state, ComposeProject $project): array
    {
        $running = array_values(array_filter(array_map(
            static fn (ContainerInfo $container): ?string => $container->running() ? $container->service() : null,
            ComposeFile::serviceContainers($this->docker->composePs($project)),
        ), is_string(...)));
        new RuntimeWriter($this->directory)->raiseGate($state, self::GATE_OPERATION);
        $writers = array_values(array_intersect(self::WRITERS, $running));
        if ($writers !== []) {
            $this->docker->composeStop($project, $writers);
        }
        if (in_array(AppRoles::QUEUE, $running, true)) {
            $this->docker->composeStop($project, [AppRoles::QUEUE], AppRoles::STOP_GRACE_SECONDS);
        }

        return $running;
    }

    /** @param  list<string>  $running */
    private function reopen(InstanceState $state, ComposeProject $project, array $running): void
    {
        try {
            $back = array_values(array_intersect([...self::WRITERS, AppRoles::QUEUE], $running));
            if ($back !== []) {
                $this->docker->composeUp($project, $back, 300);
            }
        } catch (Throwable) {
            // the backup's failure is what the caller reports; `fsa status` shows a service that did not start
        } finally {
            new RuntimeWriter($this->directory)->lowerGate($state);
        }
    }

    /** @param  list<DockerResource>  $volumes */
    private function find(array $volumes, string $volume): bool
    {
        // A restored storage volume (ADR 0085) is fsa's own, labelled with its role instead of Compose's volume label.
        return array_any($volumes, static fn (DockerResource $v): bool => ($v->labels['com.docker.compose.volume'] ?? null) === $volume
            || ($v->labels[Labels::ROLE] ?? null) === $volume || str_ends_with($v->name, '_'.$volume));
    }
}
