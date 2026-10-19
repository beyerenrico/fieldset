<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Platform\Instance\BackupRole;
use SensitiveParameter;

/**
 * SQL against a Postgres container `fsa adopt` reaches without a password (plan U19, ADR 0087): `psql` in a one-off of the
 * manifest's Postgres image that shares the container's network namespace (`--network container:<id>`) and connects to
 * 127.0.0.1, which the official image's pg_hba.conf trusts (initdb's default for local connections; the 1.0 install's
 * DB_PASSWORD is never read). The SQL goes in on stdin, never in argv. Used for the 1.0 database before its backup, for
 * the copy before 2.0 starts (snapshot, amcheck, the instance's password for the 1.0 role) and for the baseline after.
 * The socket-holding ops process itself still opens no database connection (KTD25).
 */
final readonly class DatabaseProbe
{
    public const string ROLE = 'adopt';

    public const float TIMEOUT = 1800.0;

    /**
     * amcheck (`bt_index_check` with heapallindexed) on every valid unique B-tree index in `public` with a collatable key
     * column or an expression (emails, slugs): a copy whose libc sorts text differently fails it. The extension is
     * created inside a transaction that is rolled back, so the database keeps no extension the restore check would refuse.
     */
    public const string AMCHECK = <<<'AMCHECK_SQL'
        BEGIN;
        CREATE EXTENSION IF NOT EXISTS amcheck;
        DO $fsa$
        DECLARE r record;
        BEGIN
          FOR r IN
            SELECT c.oid, c.relname FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_am am ON am.oid = c.relam JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND am.amname = 'btree' AND i.indisunique AND i.indisvalid
              AND (i.indexprs IS NOT NULL OR EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey) AND a.attcollation <> 0))
            ORDER BY c.relname
          LOOP
            BEGIN
              PERFORM bt_index_check(r.oid, true);
              RAISE NOTICE 'fsa-amcheck ok %', r.relname;
            EXCEPTION WHEN others THEN
              RAISE NOTICE 'fsa-amcheck failed % %', r.relname, SQLERRM;
            END;
          END LOOP;
        END
        $fsa$;
        ROLLBACK;
        AMCHECK_SQL;

    public function __construct(private DockerGateway $docker) {}

    /** @throws InstanceException exit 4 `snapshot_failed` */
    public function snapshot(string $image, string $container, string $database, string $user, string $uuid): DataSnapshot
    {
        $outcome = $this->psql($image, $container, $database, $user, $uuid, self::snapshotSql());
        $lines = array_values(array_filter(explode("\n", trim($outcome->stdout)), static fn (string $line): bool => str_starts_with(trim($line), '{')));
        if (! $outcome->successful() || $lines === []) {
            throw new InstanceException('The database snapshot failed: '.$outcome->stderrTail(3), ExitCode::HealthCheckFailed, 'snapshot_failed', 'Read `docker logs` of the Postgres container.', true);
        }

        return DataSnapshot::fromJson($lines[array_key_last($lines)]);
    }

    /**
     * @return array{checked: list<string>, failed: list<string>} index names, and `<index> <error>` for each failure
     *
     * @throws InstanceException exit 4 `amcheck_failed` when the check cannot run
     */
    public function amcheck(string $image, string $container, string $database, string $user, string $uuid): array
    {
        $outcome = $this->psql($image, $container, $database, $user, $uuid, self::AMCHECK);
        if (! $outcome->successful()) {
            throw new InstanceException('amcheck could not run on the copied database: '.$outcome->stderrTail(3), ExitCode::HealthCheckFailed, 'amcheck_failed', 'Read the output; the copy is not used.', true);
        }
        preg_match_all('/fsa-amcheck ok (\S+)/', $outcome->stderr, $ok);
        preg_match_all('/fsa-amcheck failed (\S+ [^\r\n]*)/', $outcome->stderr, $failed);

        return ['checked' => [...$ok[1], ...array_map(static fn (string $line): string => explode(' ', $line, 2)[0], $failed[1])], 'failed' => $failed[1]];
    }

    /**
     * Gives the 1.0 role the instance's database password, as a SCRAM-SHA-256 verifier (BackupRole::scramVerifier): the
     * copied cluster still has 1.0's, and the 2.0 app connects over the network with the instance's own.
     *
     * @throws InstanceException exit 4 `db_password_failed`
     */
    public function setPassword(string $image, string $container, string $database, string $user, #[SensitiveParameter] string $password, string $uuid): void
    {
        $sql = 'ALTER ROLE "'.$user.'" WITH PASSWORD \''.BackupRole::scramVerifier($password)."';\n";
        $outcome = $this->psql($image, $container, $database, $user, $uuid, $sql);
        if (! $outcome->successful()) {
            throw new InstanceException('The database password of the copy could not be set.', ExitCode::HealthCheckFailed, 'db_password_failed', 'Read `docker logs` of the Postgres container; the copy is not used.', true);
        }
    }

    public static function snapshotSql(): string
    {
        $counts = implode(', ', array_map(static fn (string $table): string => "'{$table}', (SELECT count(*) FROM {$table})", DataSnapshot::COUNTED));

        return <<<SQL
            SELECT json_build_object(
              'postgres', current_setting('server_version'),
              'migrations', (SELECT count(*) FROM migrations),
              'last_migration', (SELECT migration FROM migrations ORDER BY id DESC LIMIT 1),
              'counts', json_build_object({$counts}),
              'heads', COALESCE((SELECT json_object_agg(chain, json_build_object('seq', chain_seq, 'hash', hash) ORDER BY chain) FROM (
                SELECT DISTINCT ON (COALESCE(organization_id, 0)) COALESCE(organization_id, 0) AS chain, chain_seq, hash
                FROM activity_log WHERE chain_seq IS NOT NULL ORDER BY COALESCE(organization_id, 0), chain_seq DESC) heads), '{}'::json)
            )::text;

            SQL;
    }

    private function psql(string $image, string $container, string $database, string $user, string $uuid, string $sql): ProcessOutcome
    {
        return $this->docker->runContainer(new ContainerSpec(
            image: $image,
            command: ['-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-h', '127.0.0.1', '-U', $user, '-d', $database, '-f', '-'],
            labels: Labels::forInstance($uuid, self::ROLE),
            network: 'container:'.$container,
            entrypoint: 'psql',
        ), $sql, self::TIMEOUT);
    }
}
