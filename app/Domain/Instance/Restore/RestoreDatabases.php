<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Platform\Instance\BackupRole;
use SensitiveParameter;

/**
 * The database side of a restore, from the socket-holding fsa process that never connects to a database itself
 * (KTD25, plan U17, ADR 0085): `instance:restore-db` and `instance:restore-finish` run in socket-less one-offs of the app
 * service (passwords by name in their environment), and `pg_restore` runs from the app image in a throwaway container on
 * the instance's network with the backup set mounted read-only. An archive that failed or skipped the HMAC is restored
 * by a role that owns nothing but the scratch database (KTD18).
 */
final readonly class RestoreDatabases
{
    public const string ROLE = 'restore';

    /** The one-off env variable with the restricted role's password (instance:restore-db). */
    public const string ROLE_PASSWORD_ENV = 'FSA_RESTORE_DB_PASSWORD';

    /** A dump of a large instance: pg_restore rebuilds every index. */
    public const float RESTORE_TIMEOUT = 21600.0;

    private const float TIMEOUT = 600.0;

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /**
     * Creates the scratch database; with `$restrictedRole` owned by that new login role (its password returned).
     *
     * @return array{database: string, role: string|null, password: string|null}
     */
    public function create(ComposeProject $project, string $database, ?string $restrictedRole): array
    {
        $password = $restrictedRole === null ? null : bin2hex(random_bytes(24));
        $this->run($project, 'create', ['--database='.$database, ...($restrictedRole === null ? [] : ['--restricted-role='.$restrictedRole])], $password === null ? [] : [self::ROLE_PASSWORD_ENV => $password]);

        return ['database' => $database, 'role' => $restrictedRole, 'password' => $password];
    }

    public function drop(ComposeProject $project, string $database, ?string $role = null): void
    {
        $this->run($project, 'drop', ['--database='.$database, ...($role === null ? [] : ['--role='.$role])]);
    }

    /** Renames `$live` to `$aside` and `$incoming` to `$live` (back on failure of the second rename). */
    public function swap(ComposeProject $project, string $incoming, string $live, string $aside): void
    {
        $this->run($project, 'swap', ['--database='.$incoming, '--live='.$live, '--aside='.$aside]);
    }

    /** Hands everything the restricted role restored to the app's user, then drops the role. */
    public function adopt(ComposeProject $project, string $database, string $role): void
    {
        $this->run($project, 'adopt', ['--database='.$database, '--role='.$role]);
    }

    /** @return list<string> databases whose names start with `$prefix` */
    public function list(ComposeProject $project, string $prefix): array
    {
        $databases = $this->run($project, 'list', ['--prefix='.$prefix])->data['databases'] ?? [];

        return array_values(array_filter(is_array($databases) ? $databases : [], is_string(...)));
    }

    /**
     * pg_restore of the set's dump into `$database`, from the app image (PGDG 16) on the instance's network: no owner, no
     * privileges, one transaction, the first error stops it.
     *
     * @param  array{username: string, password: string}|null  $as  the restricted role; null: the app's own user
     */
    public function restore(InstanceState $state, ComposeProject $project, PreparedBackup $backup, string $database, #[SensitiveParameter] ?array $as): void
    {
        $app = new EnvFiles($this->directory)->read('app');
        $env = [
            'PGHOST' => 'postgres', 'PGPORT' => '5432', 'PGDATABASE' => $database, 'PGCONNECT_TIMEOUT' => '15',
            'PGUSER' => $as['username'] ?? ($app['DB_USERNAME'] ?? ''), 'PGPASSWORD' => $as['password'] ?? ($app['DB_PASSWORD'] ?? ''),
        ];
        $owner = $state->containerUid().':'.($state->containerUid() === 0 ? 0 : $state->hostGid());
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            command: ['--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', '--', $backup->set.'/database.dump'],
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            env: $env,
            volumes: [$backup->set.':'.$backup->set.':ro'],
            network: $project->name.'_default',
            user: $owner,
            entrypoint: 'pg_restore',
        ), null, self::RESTORE_TIMEOUT);
        if (! $outcome->successful()) {
            throw new InstanceException('pg_restore failed: '.$outcome->stderrTail(5), ExitCode::HealthCheckFailed, 'pg_restore_failed', 'Read the output; the live database was not touched.', true, ['output' => $outcome->stderrTail(10)]);
        }
    }

    /**
     * `instance:restore-finish` in the restored database: the new audit epoch and `instance.restored`, the security state
     * carried over from `$source`, sequences, stale credentials, grants and a decrypt sample.
     *
     * @param  array<string, mixed>  $input  previous_heads, previous_epoch, from_backup (stdin)
     * @param  string  $previousKeys  APP_PREVIOUS_KEYS for this one-off (comma-separated), empty for the env file's
     * @return array<string, mixed> its data
     */
    public function finish(ComposeProject $project, string $database, ?string $source, string $backup, int $seed, int $actorUid, array $input, #[SensitiveParameter] string $previousKeys = ''): array
    {
        $password = new EnvFiles($this->directory)->read(BackupService::NAME)['DB_PASSWORD'] ?? '';
        $command = ['php', 'artisan', 'instance:restore-finish', '--backup='.$backup, '--epoch-seed='.$seed, '--actor-uid='.$actorUid, ...($source === null ? [] : ['--source='.$source]), '--json'];
        // APP_PREVIOUS_KEYS by name: the decrypt sample reads data encrypted under the backup's keys (ADR 0085).
        $env = ['DB_DATABASE' => $database, BackupRole::PASSWORD_ENV => $password, ...($previousKeys === '' ? [] : ['APP_PREVIOUS_KEYS' => $previousKeys])];
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun(
            $project, ComposeRenderer::ONE_OFF_SERVICE, $command, $env,
            input: json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), timeout: self::TIMEOUT,
        ));
        if (! $outcome->ok) {
            throw new InstanceException('Finishing the restored database failed: '.$outcome->errorMessage(), ExitCode::HealthCheckFailed, $outcome->errorCode() === 'decrypt_failed' ? 'decrypt_failed' : 'restore_finish_failed', 'Read the output; the live database was not touched.', true, ['output' => $outcome->output, 'data' => $outcome->data]);
        }

        return $outcome->data;
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, string>  $env
     */
    private function run(ComposeProject $project, string $action, array $options, #[SensitiveParameter] array $env = []): OneOffResult
    {
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:restore-db', $action, ...$options, '--json'], $env, timeout: self::TIMEOUT));
        if (! $outcome->ok) {
            throw new InstanceException("The database step `{$action}` failed: ".$outcome->errorMessage(), ExitCode::HealthCheckFailed, 'restore_database_failed', 'Read the output; `fsa status` shows what is left.', true, ['action' => $action, 'code' => $outcome->errorCode(), 'output' => $outcome->output]);
        }

        return $outcome;
    }
}
