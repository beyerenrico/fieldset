<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Platform\Instance\BackupRole;
use SensitiveParameter;

/**
 * The ops side of a backup that fsa asks for (`fsa backup`, safety backups; KTD25, ADR 0084). The process holding the
 * socket never connects to the database: it starts Postgres when it is stopped, sets up the backup role in a
 * socket-less one-off of the app service (`instance:backup-role`, the password by name in the environment), then runs
 * the backup service's own entry point as a one-off of that service (`fsa backup-daemon --once`), with the service's
 * mounts, capabilities and env, under the instance lock the caller holds (passed by its ID).
 */
final readonly class BackupRunner
{
    /** A one-off backup may dump a large database and archive a large volume (BackupTools' limit and some slack). */
    public const float TIMEOUT = BackupTools::DEADLINE_SECONDS + 600.0;

    private const float START_TIMEOUT = 120.0;

    public function __construct(
        private InstanceDirectory $directory,
        private DockerGateway $docker,
    ) {}

    /**
     * @param  string|null  $secrets  `secrets.env` for `--include-secrets` (the backup must be encrypted)
     *
     * @throws InstanceException exit 3 when the instance has no backup service, exit 4 when the role cannot be set up
     */
    public function backup(ComposeProject $project, string $kind, string $lockId, #[SensitiveParameter] ?string $secrets = null): OneOffResult
    {
        $this->prepare($project);
        $command = ['fsa', 'backup-daemon', '--once', '--kind='.$kind, '--lock-id='.$lockId, '--json', ...($secrets === null ? [] : ['--include-secrets'])];

        return OneOffResult::fromOutcome($this->docker->composeRun($project, BackupService::NAME, $command, input: $secrets, timeout: self::TIMEOUT));
    }

    /** Encrypts a plain backup in place (a safety backup once its operation completed, R57). */
    public function seal(ComposeProject $project, string $name, string $lockId): OneOffResult
    {
        $this->assertService();
        $command = ['fsa', 'backup-daemon', '--seal='.$name, '--lock-id='.$lockId, '--json'];

        return OneOffResult::fromOutcome($this->docker->composeRun($project, BackupService::NAME, $command, timeout: self::TIMEOUT));
    }

    /**
     * Removes what interrupted runs left (partial sets, tombstones), which stay root's (M12 gate S1), as the service's
     * root under the caller's lock: `fsa uninstall`, whose ops container runs as the host user on rootful Docker.
     */
    public function clean(ComposeProject $project, string $lockId): OneOffResult
    {
        $this->assertService();

        return OneOffResult::fromOutcome($this->docker->composeRun($project, BackupService::NAME, ['fsa', 'backup-daemon', '--clean', '--lock-id='.$lockId, '--json'], timeout: self::START_TIMEOUT));
    }

    private function prepare(ComposeProject $project): void
    {
        $this->assertService();
        $running = array_any($this->docker->composePs($project), static fn (ContainerInfo $c): bool => $c->service() === PostgresService::NAME && $c->running());
        if (! $running) {
            $this->docker->composeUp($project, [PostgresService::NAME], (int) self::START_TIMEOUT);
        }
        $this->ensureRole($project);
    }

    /**
     * Creates or updates the backup service's database role (after install's migrations, before every manual run):
     * idempotent, the password from env/backup.env passed by name.
     *
     * @throws InstanceException exit 3 without the password, exit 4 when the one-off fails
     */
    public function ensureRole(ComposeProject $project): void
    {
        $password = new EnvFiles($this->directory)->read(BackupService::NAME)['DB_PASSWORD'] ?? '';
        if ($password === '') {
            throw new InstanceException('env/backup.env holds no password for the backup role.', ExitCode::PreflightFailed, 'backup_service_missing', 'Run `fsa install` again with the same flags to render it.');
        }
        $role = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:backup-role', '--json'], [BackupRole::PASSWORD_ENV => $password], timeout: 120.0));
        if (! $role->ok) {
            throw new InstanceException('Setting up the backup role failed: '.$role->errorMessage(), ExitCode::HealthCheckFailed, 'backup_role_failed', 'Check `fsa logs postgres` and run the command again.', true, ['output' => $role->output]);
        }
    }

    private function assertService(): void
    {
        if (! in_array(BackupService::NAME, ComposeFile::services($this->directory), true)) {
            throw new InstanceException('This instance has no backup service yet.', ExitCode::PreflightFailed, 'backup_service_missing', 'Upgrade the instance (`fsa upgrade`): the release that brings backups renders the service.');
        }
        $this->directory->ensureDirectory(BackupStore::STAGING); // its bind mount (create_host_path: false)
    }
}
