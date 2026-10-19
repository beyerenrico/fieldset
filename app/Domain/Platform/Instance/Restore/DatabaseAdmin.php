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
 * - adopt: after such a restore the objects and the database go to the superuser, the role is dropped;
 * - swap: the restored database takes the live name, the live one moves aside (kept until the restore is verified);
 *   the same call with the names exchanged swaps back;
 * - drop: a scratch or set-aside database, never the live one (DB_DATABASE) and never postgres/template0/template1;
 * - list: databases with a prefix (leftovers of an interrupted restore or drill).
 *
 * Names come from fsa and pass RestoreConnections::NAME, so quoting them as identifiers is safe. Role passwords reach
 * Postgres only as a SCRAM verifier (BackupRole::scramVerifier), and a failing role statement is reported by its
 * SQLSTATE only.
 */
final readonly class DatabaseAdmin
{
    private const int RENAME_ATTEMPTS = 3;

    /** The only roles a restore creates, adopts from or drops (review: never the app's own user). */
    public const string ROLE = '/^fsa_(?:restore|drill)_[a-z0-9_]{1,40}$/D';

    /** The only databases a restore drops: its scratch, put-aside and drill databases. */
    public const string DROPPABLE = '/(?:^|_)(?:pre_)?restore_/';

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
            throw new RestoreRefused('password_missing', 'The restricted restore role needs a password (FSA_RESTORE_DB_PASSWORD).');
        }
        $this->assertRestoreRole($role);
        $this->ensureRestrictedRole($role, $password);
        $this->admin->statement('CREATE DATABASE '.$this->quote($database).' OWNER '.$role);
        $this->admin->statement('REVOKE ALL ON DATABASE '.$this->quote($database).' FROM PUBLIC');
        $this->admin->statement('GRANT CONNECT, TEMPORARY ON DATABASE '.$this->quote($database).' TO '.$role);

        return ['database' => $database, 'owner' => $role, 'restricted' => true];
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
        $this->rename($live, $aside);

        try {
            $this->rename($incoming, $live);
        } catch (QueryException $e) {
            $this->rename($aside, $live);

            throw new RestoreRefused('swap_failed', "Renaming {$incoming} to {$live} failed; {$live} is unchanged: ".$e->getMessage(), null, $e);
        }

        return ['live' => $live, 'aside' => $aside, 'incoming' => $incoming];
    }

    /**
     * After a restricted restore: everything the role owns in the database (and the database itself) goes to the
     * superuser, the role's rights are revoked, PUBLIC gets its default CONNECT and TEMPORARY back, and the role goes.
     *
     * @return array{database: string, role: string}
     */
    public function adopt(string $database, string $role): array
    {
        $this->assertNotReserved($database);
        $this->assertRestoreRole($role);
        if (! $this->exists($database) || ! $this->roleExists($role)) {
            throw new RestoreRefused('database_missing', "The database {$database} or the role {$role} does not exist.");
        }
        $db = RestoreConnections::to($database, 'fsa_restore_adopt');

        try {
            $db->statement('REASSIGN OWNED BY '.$role.' TO CURRENT_USER');
            $db->statement('DROP OWNED BY '.$role);
        } finally {
            RestoreConnections::close('fsa_restore_adopt');
        }
        $this->admin->statement('ALTER DATABASE '.$this->quote($database).' OWNER TO CURRENT_USER');
        $this->admin->statement('GRANT CONNECT, TEMPORARY ON DATABASE '.$this->quote($database).' TO PUBLIC');
        $this->admin->statement('DROP ROLE '.$role);

        return ['database' => $database, 'role' => $role];
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

    private function rename(string $from, string $to): void
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
            throw new RestoreRefused('invalid_role', "{$role} is no role a restore creates (fsa_restore_… or fsa_drill_…).");
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
