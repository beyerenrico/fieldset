<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;

/**
 * The database role of an fsa instance's backup service (plan KTD7, KTD15, U16; ADR 0084): it logs in with its own
 * password (env/backup.env), reads every table through `pg_read_all_data` for the dump and the snapshot queries, and may
 * insert into `backup_runs`, nothing else. `instance:backup-role` creates or updates it in a socket-less one-off of the
 * app service, whose database user is the image's superuser.
 *
 * The password reaches Postgres only as a SCRAM-SHA-256 verifier computed here (RFC 5802/7677, the format Postgres
 * stores): a CREATE/ALTER ROLE that fails is logged by the server with its statement text, and the plain password
 * must never be in it. A failure is reported without the statement for the same reason.
 */
final readonly class BackupRole
{
    public const string NAME = 'fsa_backup';

    /** The one-off's environment variable that carries the password (never an argument). */
    public const string PASSWORD_ENV = 'FSA_BACKUP_DB_PASSWORD';

    public const int SCRAM_ITERATIONS = 4096;

    private const string ROLE = '/^[a-z_][a-z0-9_]{0,62}$/D';

    public function __construct(private ?Connection $db = null) {}

    /**
     * Creates the role or resets its password and rights (an existing role loses any right granted to it by hand);
     * idempotent.
     *
     * @return array{role: string, created: bool, granted: list<string>}
     */
    public function ensure(#[SensitiveParameter] string $password, string $role = self::NAME): array
    {
        if (preg_match(self::ROLE, $role) !== 1 || $password === '') {
            throw new RuntimeException('The backup role needs a lowercase name and a password.');
        }
        $db = $this->db ?? DB::connection();
        if (! $db instanceof Connection) {
            throw new RuntimeException('The backup role needs a database connection.');
        }
        $verifier = $db->getPdo()->quote(self::scramVerifier($password));
        $created = $db->selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$role]) === null;
        $database = (string) $db->getDatabaseName();

        $failed = null;

        try {
            $db->statement(($created ? 'CREATE ROLE ' : 'ALTER ROLE ').$role.' WITH LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$verifier);
            if (! $created) {
                $this->revokeExtras($db, $role);
            }
            $db->statement("GRANT pg_read_all_data TO {$role}");
            $db->statement('GRANT CONNECT ON DATABASE '.$db->getQueryGrammar()->wrap($database).' TO '.$role);
            $db->statement("GRANT INSERT ON TABLE backup_runs TO {$role}");
        } catch (QueryException $e) {
            $failed = (string) ($e->errorInfo[0] ?? '?'); // the exception (and its SQL with the verifier) goes no further
        }
        if ($failed !== null) {
            throw new RuntimeException("Could not set up the backup role {$role} (SQLSTATE {$failed}).");
        }

        return ['role' => $role, 'created' => $created, 'granted' => ['pg_read_all_data', 'connect', 'insert:backup_runs']];
    }

    /**
     * An existing role loses what it may have gained besides its own rights: other role memberships, rights on the
     * tables, sequences and schema of `public`, and per-role settings. The grants that follow give back exactly its own.
     */
    private function revokeExtras(Connection $db, string $role): void
    {
        $memberships = $db->select('SELECT r.rolname FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.roleid JOIN pg_roles u ON u.oid = m.member WHERE u.rolname = ? AND r.rolname <> ?', [$role, 'pg_read_all_data']);
        foreach ($memberships as $membership) {
            $db->statement('REVOKE '.$db->getQueryGrammar()->wrap((string) $membership->rolname).' FROM '.$role);
        }
        $db->statement("REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM {$role}");
        $db->statement("REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM {$role}");
        $db->statement("REVOKE ALL PRIVILEGES ON SCHEMA public FROM {$role}");
        $db->statement("ALTER ROLE {$role} RESET ALL");
    }

    /**
     * `SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>`, base64 parts, as `pg_authid.rolpassword` holds it.
     *
     * @param  positive-int  $iterations
     */
    public static function scramVerifier(#[SensitiveParameter] string $password, ?string $salt = null, int $iterations = self::SCRAM_ITERATIONS): string
    {
        $salt ??= random_bytes(16);
        $salted = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);

        return sprintf(
            'SCRAM-SHA-256$%d:%s$%s:%s',
            $iterations,
            base64_encode($salt),
            base64_encode(hash('sha256', $clientKey, true)),
            base64_encode(hash_hmac('sha256', 'Server Key', $salted, true)),
        );
    }
}
