<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Platform\Instance\BackupRole;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use SensitiveParameter;
use stdClass;

/**
 * Database-level steps of a restore (plan U17, KTD18, ADR 0085), run on the maintenance database by the instance's
 * superuser in a socket-less one-off (`instance:restore-db`):
 *
 * - create: the scratch database a backup is restored into; for an archive without a valid HMAC it belongs to a
 *   restricted role (no superuser, no CREATEDB/CREATEROLE) that pg_restore then logs in as, and PUBLIC may not connect;
 *   the superuser installs the extensions the migrations use first, so the role owns neither them nor their members;
 * - adopt: after such a restore the objects and the database go to the superuser, grants to anyone but the owner and
 *   the database's settings go, the role is dropped;
 * - swap: the restored database takes the live name, the live one moves aside (kept until the restore is verified);
 *   the same call with the names exchanged swaps back;
 * - rename: a restore-made database to a free name (the put-aside one back to the live name after a half swap);
 * - drop: a scratch or set-aside database, never the live one (DB_DATABASE) and never postgres/template0/template1;
 * - list: databases with a prefix (leftovers of an interrupted restore or drill).
 *
 * Names come from fieldset and pass RestoreConnections::NAME, so quoting them as identifiers is safe. Role passwords reach
 * Postgres only as a SCRAM verifier (BackupRole::scramVerifier), and a failing role statement is reported by its
 * SQLSTATE only.
 */
final readonly class DatabaseAdmin
{
    private const int RENAME_ATTEMPTS = 3;

    /** The only roles a restore creates, adopts from or drops (review: never the app's own user). */
    public const string ROLE = '/^fieldset_(?:restore|drill)_[a-z0-9_]{1,40}$/D';

    /** The only databases a restore drops or renames: its scratch, put-aside and drill databases. */
    public const string DROPPABLE = '/(?:^|_)(?:pre_)?restore_/';

    /** Extensions the migrations create, installed by the superuser before a restricted restore (review S1). */
    private const array EXTENSIONS = ['pg_trgm'];

    /**
     * Explicit grants to anyone but the owner on schema-public tables, sequences, columns and routines, as targets and
     * grantees Postgres quoted itself (review S2). The adopt connection's search_path is pg_catalog, so routines print
     * schema-qualified.
     */
    private const string GRANTS = <<<'SQL'
        SELECT DISTINCT o.target, CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(a.grantee)) END AS grantee
        FROM (
            SELECT CASE WHEN c.relkind = 'S' THEN 'SEQUENCE ' ELSE 'TABLE ' END || quote_ident(n.nspname) || '.' || quote_ident(c.relname) AS target, c.relowner AS owner, c.relacl AS acl
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')
            UNION ALL
            SELECT 'TABLE ' || quote_ident(n.nspname) || '.' || quote_ident(c.relname), c.relowner, at.attacl
            FROM pg_attribute at JOIN pg_class c ON c.oid = at.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND at.attacl IS NOT NULL
            UNION ALL
            SELECT 'ROUTINE ' || p.oid::regprocedure::text, p.proowner, p.proacl FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public'
        ) o CROSS JOIN LATERAL aclexplode(o.acl) a
        WHERE a.grantee <> o.owner
        ORDER BY 1, 2
        SQL;

    public function __construct(private Connection $admin) {}

    public function exists(string $database): bool
    {
        return $this->admin->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [$database]) !== null;
    }

    /** @return list<string> databases whose name starts with the prefix, sorted */
    public function list(string $prefix): array
    {
        $rows = $this->admin->select('SELECT datname FROM pg_database WHERE left(datname, char_length(?)) = ? ORDER BY datname', [$prefix, $prefix]);

        return array_values(array_map(static fn (stdClass $row): string => (string) $row->datname, $rows));
    }

    /** @return array{database: string, owner: string, restricted: bool} */
    public function create(string $database, ?string $role = null, #[SensitiveParameter] ?string $password = null): array
    {
        $this->assertNotReserved($database);
        if ($this->exists($database)) {
            throw new RestoreRefused('database_exists', "The database {$database} exists already; a restore never writes into an existing database.");
        }
        if ($role === null) {
            $this->admin->statement('CREATE DATABASE '.$this->quote($database));

            return ['database' => $database, 'owner' => $this->currentUser(), 'restricted' => false];
        }
        if ($password === null || $password === '') {
            throw new RestoreRefused('password_missing', 'The restricted restore role needs a password (FIELDSET_RESTORE_DB_PASSWORD).');
        }
        $this->assertRestoreRole($role);
        $this->ensureRestrictedRole($role, $password);
        $this->admin->statement('CREATE DATABASE '.$this->quote($database).' OWNER '.$role);
        $this->admin->statement('REVOKE ALL ON DATABASE '.$this->quote($database).' FROM PUBLIC');
        $this->admin->statement('GRANT CONNECT, TEMPORARY ON DATABASE '.$this->quote($database).' TO '.$role);
        $this->installExtensions($database);

        return ['database' => $database, 'owner' => $role, 'restricted' => true];
    }

    /**
     * The schema audit's baseline for an older archive (AuditBaseline, review S3): created like a restricted restore's
     * scratch database, with EXTENSIONS installed by the superuser, so the two differ only by what the migrations made.
     * Its name must be one drop() accepts (DROPPABLE).
     */
    public function createReference(string $database): void
    {
        if (preg_match(self::DROPPABLE, $database) !== 1) {
            throw new RestoreRefused('refused_database', "{$database} is no name a restore may drop; the audit's reference needs one.");
        }
        $this->create($database);
        $this->installExtensions($database);
    }

    /**
     * A restore-made database (DROPPABLE) takes a free name: the put-aside database goes back to the live name when a
     * swap stopped half-way.
     *
     * @return array{from: string, to: string}
     */
    public function rename(string $from, string $to): array
    {
        $this->assertNotReserved($from);
        $this->assertNotReserved($to);
        if (preg_match(self::DROPPABLE, $from) !== 1) {
            throw new RestoreRefused('refused_database', "{$from} is no scratch, put-aside or drill database of a restore; it is never renamed here.");
        }
        if (! $this->exists($from)) {
            throw new RestoreRefused('database_missing', "The database {$from} does not exist.");
        }
        if ($this->exists($to)) {
            throw new RestoreRefused('database_exists', "The database {$to} exists already.");
        }
        $this->alterName($from, $to);

        return ['from' => $from, 'to' => $to];
    }

    /** @return array{database: string, dropped: bool, role_dropped: bool} */
    public function drop(string $database, ?string $role, string $live): array
    {
        $this->assertNotReserved($database);
        if ($database === $live) {
            throw new RestoreRefused('refused_live_database', "{$database} is the instance's live database; a restore never drops it.");
        }
        if (preg_match(self::DROPPABLE, $database) !== 1) {
            throw new RestoreRefused('refused_database', "{$database} is no scratch, put-aside or drill database of a restore; it is never dropped here.");
        }
        if ($role !== null) {
            $this->assertRestoreRole($role);
        }
        $existed = $this->exists($database);
        $this->admin->statement('DROP DATABASE IF EXISTS '.$this->quote($database).' WITH (FORCE)');
        $roleDropped = false;
        if ($role !== null && $this->roleExists($role)) {
            $this->admin->statement('DROP ROLE '.$role);
            $roleDropped = true;
        }

        return ['database' => $database, 'dropped' => $existed, 'role_dropped' => $roleDropped];
    }

    /**
     * The incoming database takes the live name; the live one moves aside. If the second rename fails, the first is
     * undone, so the live name always points at a complete database.
     *
     * @return array{live: string, aside: string, incoming: string}
     */
    public function swap(string $incoming, string $live, string $aside): array
    {
        foreach ([$incoming, $live, $aside] as $name) {
            $this->assertNotReserved($name);
        }
        if (count(array_unique([$incoming, $live, $aside])) !== 3) {
            throw new RestoreRefused('invalid_swap', 'The incoming, live and aside databases must be three different names.');
        }
        foreach ([$incoming, $live] as $name) {
            if (! $this->exists($name)) {
                throw new RestoreRefused('database_missing', "The database {$name} does not exist.");
            }
        }
        if ($this->exists($aside)) {
            throw new RestoreRefused('database_exists', "The database {$aside} exists already.");
        }
        $this->alterName($live, $aside);

        try {
            $this->alterName($incoming, $live);
        } catch (QueryException $e) {
            $this->alterName($aside, $live);

            throw new RestoreRefused('swap_failed', "Renaming {$incoming} to {$live} failed; {$live} is unchanged: ".$e->getMessage(), null, $e);
        }

        return ['live' => $live, 'aside' => $aside, 'incoming' => $incoming];
    }

    /**
     * After a restricted restore: everything the role owns in the database (and the database itself) goes to the
     * superuser, the role's rights are revoked, so are grants to anyone else on schema-public tables, sequences and
     * routines (BackupRole grants again in instance:restore-finish), the database's settings are reset, PUBLIC gets
     * only its default CONNECT and TEMPORARY back, and the role goes.
     *
     * @return array{database: string, role: string, revoked: int, settings_reset: int}
     */
    public function adopt(string $database, string $role): array
    {
        $this->assertNotReserved($database);
        $this->assertRestoreRole($role);
        if (! $this->exists($database) || ! $this->roleExists($role)) {
            throw new RestoreRefused('database_missing', "The database {$database} or the role {$role} does not exist.");
        }
        $db = RestoreConnections::to($database, 'fieldset_restore_adopt');

        try {
            $db->statement('SET search_path = pg_catalog');
            $db->statement('REASSIGN OWNED BY '.$role.' TO CURRENT_USER');
            $db->statement('DROP OWNED BY '.$role);
            $revoked = $this->revokeGrants($db);
        } finally {
            RestoreConnections::close('fieldset_restore_adopt');
        }
        $settings = $this->resetSettings($database);
        $this->admin->statement('ALTER DATABASE '.$this->quote($database).' OWNER TO CURRENT_USER');
        $this->admin->statement('REVOKE ALL ON DATABASE '.$this->quote($database).' FROM PUBLIC');
        $this->admin->statement('GRANT CONNECT, TEMPORARY ON DATABASE '.$this->quote($database).' TO PUBLIC');
        $this->admin->statement('DROP ROLE '.$role);

        return ['database' => $database, 'role' => $role, 'revoked' => $revoked, 'settings_reset' => $settings];
    }

    /** The superuser installs EXTENSIONS in the new database, so a restricted restore finds them installed (review S1). */
    private function installExtensions(string $database): void
    {
        $db = RestoreConnections::to($database, 'fieldset_restore_create');

        try {
            foreach (self::EXTENSIONS as $extension) {
                $db->statement('CREATE EXTENSION IF NOT EXISTS '.$extension);
            }
        } finally {
            RestoreConnections::close('fieldset_restore_create');
        }
    }

    /** Revokes every explicit grant to anyone but the owner (GRANTS); the number of REVOKE statements. */
    private function revokeGrants(Connection $db): int
    {
        $grants = $db->select(self::GRANTS);
        foreach ($grants as $grant) {
            $db->statement('REVOKE ALL ON '.$grant->target.' FROM '.$grant->grantee.' CASCADE');
        }

        return count($grants);
    }

    /** Resets the database's own and its per-role settings (pg_db_role_setting); the number of rows there were. */
    private function resetSettings(string $database): int
    {
        $rows = $this->admin->select('SELECT s.setrole, quote_ident(r.rolname) AS role FROM pg_db_role_setting s JOIN pg_database d ON d.oid = s.setdatabase LEFT JOIN pg_roles r ON r.oid = s.setrole WHERE d.datname = ?', [$database]);
        foreach ($rows as $row) {
            if ((int) $row->setrole !== 0 && is_string($row->role)) {
                $this->admin->statement('ALTER ROLE '.$row->role.' IN DATABASE '.$this->quote($database).' RESET ALL');
            }
        }
        $this->admin->statement('ALTER DATABASE '.$this->quote($database).' RESET ALL');

        return count($rows);
    }

    private function ensureRestrictedRole(string $role, #[SensitiveParameter] string $password): void
    {
        $verifier = $this->admin->getPdo()->quote(BackupRole::scramVerifier($password));
        $verb = $this->roleExists($role) ? 'ALTER ROLE ' : 'CREATE ROLE ';

        $failed = null;

        try {
            $this->admin->statement($verb.$role.' WITH LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '.$verifier);
        } catch (QueryException $e) {
            $failed = (string) ($e->errorInfo[0] ?? '?'); // the exception (and its statement with the verifier) goes no further
        }
        if ($failed !== null) {
            throw new RestoreRefused('role_failed', "Could not set up the restricted restore role (SQLSTATE {$failed}).");
        }
    }

    private function alterName(string $from, string $to): void
    {
        for ($attempt = 1; ; $attempt++) {
            $this->admin->select('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$from]);

            try {
                $this->admin->statement('ALTER DATABASE '.$this->quote($from).' RENAME TO '.$this->quote($to));

                return;
            } catch (QueryException $e) {
                if ($attempt >= self::RENAME_ATTEMPTS || ($e->errorInfo[0] ?? null) !== '55006') { // object_in_use: a backend came back
                    throw $e;
                }
                usleep(200_000);
            }
        }
    }

    private function roleExists(string $role): bool
    {
        return $this->admin->selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    private function currentUser(): string
    {
        return (string) $this->admin->selectOne('SELECT current_user AS name')?->name;
    }

    private function assertRestoreRole(string $role): void
    {
        if (preg_match(self::ROLE, $role) !== 1) {
            throw new RestoreRefused('invalid_role', "{$role} is no role a restore creates (fieldset_restore_… or fieldset_drill_…).");
        }
    }

    private function assertNotReserved(string $database): void
    {
        if (in_array($database, RestoreConnections::RESERVED, true)) {
            throw new RestoreRefused('reserved_database', "{$database} is a Postgres system database.");
        }
    }

    private function quote(string $name): string
    {
        return '"'.$name.'"';
    }
}
