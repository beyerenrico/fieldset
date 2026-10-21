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
use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

/**
 * The database side of a restore, from the socket-holding fieldset process that never connects to a database itself
 * (KTD25, plan U17, ADR 0085): `instance:restore-db` and `instance:restore-finish` run in socket-less one-offs of the app
 * service (passwords by name in their environment), and `pg_restore` runs from the app image in a throwaway container on
 * the instance's network with the backup set mounted read-only. An archive that failed or skipped the HMAC is restored
 * by a role that owns nothing but the scratch database (KTD18).
 */
final readonly class RestoreDatabases
{
    public const string ROLE = 'restore';

    /** The one-off env variable with the restricted role's password (instance:restore-db). */
    public const string ROLE_PASSWORD_ENV = 'FIELDSET_RESTORE_DB_PASSWORD';

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

    /** Renames a restore-made database back to the live name (the put-aside one when a swap stopped half-way). */
    public function rename(ComposeProject $project, string $from, string $to): void
    {
        $this->run($project, 'rename', ['--database='.$from, '--to='.$to]);
    }

    /** Hands everything the restricted role restored to the app's user, then drops the role. */
    public function adopt(ComposeProject $project, string $database, string $role): void
    {
        $this->run($project, 'adopt', ['--database='.$database, '--role='.$role]);
    }

    /**
     * The schema check of an unauthenticated archive before its objects go to the app's user: every object that carries
     * code (functions, triggers, defaults, indexes, checks, views, rules, policies …) must equal the reference database's,
     * which this image migrated (ADR 0085).
     *
     * @throws InstanceException exit 2 `backup_unsafe`
     */
    public function audit(ComposeProject $project, string $database, string $reference): void
    {
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:restore-db', 'audit', '--database='.$database, '--reference='.$reference, '--json'], timeout: self::TIMEOUT));
        if ($outcome->ok) {
            return;
        }
        if ($outcome->errorCode() === 'schema_mismatch') {
            throw new InstanceException((string) preg_replace('/[\x00-\x1F\x7F]/', '?', $outcome->errorMessage()), ExitCode::InvalidInput, 'backup_unsafe', 'Nothing was swapped in. Restore only archives this app wrote.');
        }

        throw new InstanceException('The schema check failed: '.$outcome->errorMessage(), ExitCode::HealthCheckFailed, 'restore_database_failed', 'Read the output; the live database was not touched.', true, ['output' => $outcome->output]);
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
        // the restricted role skips the extension the superuser created beforehand (review S1)
        $list = $as === null ? null : ($backup->dump['list'] ?? throw new InstanceException('The prepare step wrote no table of contents for the restricted restore.', ExitCode::HealthCheckFailed, 'prepare_failed', 'Run the command again; nothing was changed.'));
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            // pg_restore connects only with --dbname (PGDATABASE alone makes it print SQL); the name is no secret.
            command: ['--dbname='.$database, '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', ...($list === null ? [] : ['--use-list='.$list]), '--', $backup->set.'/database.dump'],
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            env: $env,
            volumes: [$backup->set.':'.$backup->set.':ro', ...($list === null ? [] : [$list.':'.$list.':ro'])],
            network: $project->name.'_default',
            user: $owner,
            entrypoint: 'pg_restore',
        )->hardened(), null, self::RESTORE_TIMEOUT);
        if (! $outcome->successful()) {
            $tail = static fn (int $lines): string => (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '?', $outcome->stderrTail($lines)); // names from the archive
            throw new InstanceException('pg_restore failed: '.$tail(5), ExitCode::HealthCheckFailed, 'pg_restore_failed', 'Read the output; the live database was not touched.', true, ['output' => $tail(10)]);
        }
    }

    /**
     * `instance:restore-finish` in the restored database: the new audit epoch and `instance.restored`, the security state
     * carried over from `$source`, sequences, stale credentials, grants and a decrypt sample.
     *
     * @param  array<string, mixed>  $input  previous_heads, previous_epoch, from_backup (stdin)
     * @param  string  $previousKeys  APP_PREVIOUS_KEYS for this one-off (comma-separated), empty for the env file's
     * @param  string  $reason  `restored`, or `rolled_back` for U18's rollbacks (`instance.rolled_back`, ADR 0086)
     * @param  bool  $carry  false: no security-state carry and no sequence floors (the automatic rollback of an upgrade)
     * @return array<string, mixed> its data
     */
    public function finish(ComposeProject $project, string $database, ?string $source, PreparedBackup $backup, int $seed, int $actorUid, array $input, string $journal, #[SensitiveParameter] string $previousKeys = '', string $reason = 'restored', bool $carry = true): array
    {
        $password = new EnvFiles($this->directory)->read(BackupService::NAME)['DB_PASSWORD'] ?? '';
        $createdAt = self::createdAt($backup->manifest['created_at'] ?? null);
        $command = ['php', 'artisan', 'instance:restore-finish', '--backup='.$backup->name, '--epoch-seed='.$seed, '--actor-uid='.$actorUid, ...($source === null ? [] : ['--source='.$source]),
            '--journal='.$journal, ...($createdAt === null ? [] : ['--backup-created-at='.$createdAt]), // the carry's window and an idempotent epoch (third review)
            ...($reason === 'restored' ? [] : ['--reason='.$reason]), ...($carry ? [] : ['--no-carry']), '--json'];
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

    /** The manifest's creation time as ISO 8601 UTC (fieldset's `2026-10-19T12:00:00Z`, 1.0's `20261019T120000Z`), null otherwise. */
    public static function createdAt(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        foreach (['Y-m-d\\TH:i:s\\Z', 'Ymd\\THis\\Z', DATE_ATOM] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, string>  $env
     */
    private function run(ComposeProject $project, string $action, array $options, #[SensitiveParameter] array $env = []): OneOffResult
    {
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:restore-db', $action, ...$options, '--json'], $env, timeout: self::TIMEOUT));
        if (! $outcome->ok) {
            throw new InstanceException("The database step `{$action}` failed: ".$outcome->errorMessage(), ExitCode::HealthCheckFailed, 'restore_database_failed', 'Read the output; `fieldset status` shows what is left.', true, ['action' => $action, 'code' => $outcome->errorCode(), 'output' => $outcome->output]);
        }

        return $outcome;
    }
}
