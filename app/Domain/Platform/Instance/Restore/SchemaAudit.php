<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\Connection;
use stdClass;

/**
 * The schema check of an unauthenticated archive (plan U17, KTD18, ADR 0085 amendment after review): before the restored
 * database is handed to the superuser, every object that carries code must be one this image's migrations create.
 *
 * - Exactly, by name and printed definition: functions (outside extensions), aggregates, triggers (with their enabled
 *   state), rules, policies, views, event triggers, schemas, operators, casts and domain or enum types; extensions by
 *   version, owner and their members with each member's owner (review S1: a role that owns an extension could add its
 *   own function to it, out of the function check's sight); grants to anyone but an object's owner (review S2).
 * - Never: settings of the restored database (pg_db_role_setting), by parameter name, not value (review S2).
 * - By name and by the functions their expressions call: column defaults (generated columns included), check
 *   constraints and indexes. Postgres prints a re-parsed expression differently after a dump and restore (`IN (…)`
 *   becomes `= ANY (ARRAY[…])` with other casts), so their text cannot be compared, and it records no dependency on
 *   built-in functions; what they call can be compared: every `name(` of the printed expression (string literals left
 *   out) must be one the reference's own expressions use, so `pg_read_file(…)` in a default is refused.
 *
 * The baseline is the reference at the restored database's own migration set (AuditBaseline: the live database when the
 * sets are equal, else a database this image migrates to exactly that set), so the restored database may neither have
 * other or changed objects nor lack one the baseline has (review S3, U18: an archive could leave out an append-only
 * trigger, `audit_seal` or a policy). Grants are the exception: a missing grant only takes a privilege away, and
 * restores never carry privileges. The catalog functions used only print definitions and read dependencies; no restored
 * code runs.
 */
final readonly class SchemaAudit
{
    private const string USER_SCHEMAS = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND n.nspname NOT LIKE 'pg_temp_%' AND n.nspname NOT LIKE 'pg_toast_temp_%'";

    /** Settings stored for the current database, its own (`every role`) and per role, by parameter name. */
    private const string SETTINGS = <<<'SQL'
        SELECT CASE WHEN s.setrole = 0 THEN 'every role' ELSE pg_get_userbyid(s.setrole) END AS role,
            array_to_string(ARRAY(SELECT split_part(c, '=', 1) FROM unnest(s.setconfig) c ORDER BY 1), ', ') AS names
        FROM pg_db_role_setting s JOIN pg_database d ON d.oid = s.setdatabase WHERE d.datname = current_database() ORDER BY 1
        SQL;

    /** Version, owner and the md5 of the sorted members, each with its owner (`-` for kinds without one). */
    private const string EXTENSIONS = <<<'SQL'
        SELECT e.extname AS name, e.extversion || ' owner ' || pg_get_userbyid(e.extowner) || ' members ' || coalesce(md5(string_agg(m.member, ',' ORDER BY m.member)), '') AS definition
        FROM pg_extension e LEFT JOIN LATERAL (
            SELECT pg_describe_object(d.classid, d.objid, d.objsubid) || ' by ' || coalesce(pg_get_userbyid(CASE d.classid
                WHEN 'pg_proc'::regclass THEN (SELECT proowner FROM pg_proc WHERE oid = d.objid)
                WHEN 'pg_type'::regclass THEN (SELECT typowner FROM pg_type WHERE oid = d.objid)
                WHEN 'pg_operator'::regclass THEN (SELECT oprowner FROM pg_operator WHERE oid = d.objid)
                WHEN 'pg_opclass'::regclass THEN (SELECT opcowner FROM pg_opclass WHERE oid = d.objid)
                WHEN 'pg_opfamily'::regclass THEN (SELECT opfowner FROM pg_opfamily WHERE oid = d.objid)
                WHEN 'pg_class'::regclass THEN (SELECT relowner FROM pg_class WHERE oid = d.objid)
                WHEN 'pg_namespace'::regclass THEN (SELECT nspowner FROM pg_namespace WHERE oid = d.objid)
                WHEN 'pg_language'::regclass THEN (SELECT lanowner FROM pg_language WHERE oid = d.objid)
            END), '-') AS member
            FROM pg_depend d WHERE d.refclassid = 'pg_extension'::regclass AND d.refobjid = e.oid AND d.deptype = 'e'
        ) m ON true
        GROUP BY e.oid, e.extname, e.extversion, e.extowner
        SQL;

    /**
     * Objects of `$restored` that `$baseline` (the reference at the restored database's migration set) does not have
     * with the same definition, and objects of `$baseline` that `$restored` lacks (at most 50).
     *
     * @return list<string>
     */
    public static function mismatches(Connection $restored, Connection $baseline): array
    {
        $problems = [];
        $expected = self::objects($baseline);
        $actual = self::objects($restored);
        foreach ($actual as $key => $definition) {
            if (! array_key_exists($key, $expected)) {
                $problems[] = "unknown {$key}";
            } elseif ($expected[$key] !== $definition) {
                $problems[] = "changed {$key}";
            }
        }
        $known = self::expressions($baseline);
        $restoredExpressions = self::expressions($restored);
        $allowed = array_values(array_unique(array_merge([], ...array_values($known))));
        foreach ($restoredExpressions as $key => $calls) {
            if (! array_key_exists($key, $known)) {
                $problems[] = "unknown {$key}";

                continue;
            }
            foreach (array_diff($calls, $allowed) as $call) {
                $problems[] = "{$key} calls {$call}()";
            }
        }
        foreach ([...array_keys(array_diff_key($expected, $actual)), ...array_keys(array_diff_key($known, $restoredExpressions))] as $key) {
            if (! str_starts_with($key, 'acl:')) { // a missing grant only takes a privilege away
                $problems[] = "missing {$key}";
            }
        }
        foreach ($restored->select(self::SETTINGS) as $row) {
            $problems[] = 'database setting '.self::text($row, 'names').' for '.self::text($row, 'role');
        }

        return array_slice($problems, 0, 50);
    }

    /**
     * The migrations a database ran (its `public.migrations` table), sorted; null without a plain table of that name.
     * Read only when it is an ordinary table without row security whose `migration` column is varchar, sorted in PHP:
     * no restored rule, policy, operator or type function runs (a view or another shape counts as no table, and every
     * object of the restored database is then unknown to the empty baseline).
     *
     * @return list<string>|null
     */
    public static function migrations(Connection $db): ?array
    {
        $plain = $db->selectOne(<<<'SQL'
            SELECT 1 AS plain FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'migration' AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relname = 'migrations' AND c.relkind = 'r' AND NOT c.relrowsecurity
                AND a.atttypid = 'pg_catalog.varchar'::pg_catalog.regtype
            SQL);
        if ($plain === null) {
            return null;
        }
        $names = array_values(array_unique(array_map(static fn (stdClass $row): string => self::text($row, 'migration'), $db->select('SELECT migration FROM public.migrations'))));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return array<string, string> kind:name => definition of the exactly compared objects */
    public static function objects(Connection $db): array
    {
        $objects = [];
        foreach (self::exact() as $kind => $sql) {
            foreach ($db->select($sql) as $row) {
                $objects[$kind.':'.self::text($row, 'name')] = self::text($row, 'definition');
            }
        }
        ksort($objects);

        return $objects;
    }

    /** @return array<string, list<string>> kind:name => the names the printed expression calls */
    public static function expressions(Connection $db): array
    {
        $objects = [];
        foreach (self::byCalls() as $kind => $sql) {
            foreach ($db->select($sql) as $row) {
                $objects[$kind.':'.self::text($row, 'name')] = self::calls(self::text($row, 'definition'));
            }
        }
        ksort($objects);

        return $objects;
    }

    /** @return list<string> every `name(` of an expression, lowercase, string literals and quoted names left out */
    public static function calls(string $expression): array
    {
        $bare = (string) preg_replace(["/'(?:[^']|'')*'/", '/"(?:[^"]|"")*"/'], ["''", 'q'], $expression);
        preg_match_all('/([A-Za-z_][A-Za-z0-9_$]*)\s*\(/', $bare, $matches);
        $calls = array_values(array_unique(array_map(strtolower(...), $matches[1])));
        sort($calls);

        return $calls;
    }

    /** @return array<string, string> */
    private static function exact(): array
    {
        $user = self::USER_SCHEMAS;
        $notExtension = "NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')";

        return [
            'function' => "SELECT n.nspname || '.' || p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')' AS name, md5(pg_get_functiondef(p.oid)) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE {$user} AND p.prokind <> 'a' AND {$notExtension}",
            'aggregate' => "SELECT n.nspname || '.' || p.proname AS name, 'aggregate' AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE {$user} AND p.prokind = 'a' AND {$notExtension}",
            'trigger' => "SELECT c.relname || '.' || t.tgname AS name, pg_get_triggerdef(t.oid) || ' enabled ' || t.tgenabled::text AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND {$user}",
            'rule' => "SELECT r.tablename || '.' || r.rulename AS name, r.definition AS definition FROM pg_rules r WHERE r.schemaname NOT IN ('pg_catalog', 'information_schema')",
            'policy' => "SELECT p.tablename || '.' || p.policyname AS name, coalesce(p.qual, '') || '|' || coalesce(p.with_check, '') AS definition FROM pg_policies p",
            'view' => "SELECT n.nspname || '.' || c.relname AS name, c.relkind::text || pg_get_viewdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('v', 'm') AND {$user}",
            'event_trigger' => 'SELECT evtname AS name, evtevent AS definition FROM pg_event_trigger',
            'schema' => "SELECT n.nspname AS name, 'schema' AS definition FROM pg_namespace n WHERE {$user}",
            'extension' => self::EXTENSIONS,
            'acl' => self::acl(),
            'operator' => "SELECT n.nspname || '.' || o.oprname || '(' || o.oprleft::regtype || ',' || o.oprright::regtype || ')' AS name, o.oprcode::text AS definition FROM pg_operator o JOIN pg_namespace n ON n.oid = o.oprnamespace WHERE {$user} AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = o.oid AND d.deptype = 'e')",
            'cast' => "SELECT k.castsource::regtype || '->' || k.casttarget::regtype AS name, k.castfunc::text AS definition FROM pg_cast k WHERE k.oid >= 16384 AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = k.oid AND d.deptype = 'e')",
            'type' => "SELECT n.nspname || '.' || t.typname AS name, t.typtype::text || coalesce(pg_catalog.format_type(t.typbasetype, t.typtypmod), '') AS definition FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE t.typtype IN ('d', 'e', 'r', 'm') AND {$user} AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = t.oid AND d.deptype = 'e')",
        ];
    }

    /**
     * Grants to anyone but the object's owner, as `<kind> <object> <grantee> <privilege>` (`*` with grant option):
     * relations, columns, routines, schemas, types, languages, the database itself and default privileges.
     */
    private static function acl(): string
    {
        $user = self::USER_SCHEMAS;

        return <<<SQL
            SELECT o.kind || ' ' || o.object || ' ' || CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END || ' ' || a.privilege_type || CASE WHEN a.is_grantable THEN '*' ELSE '' END AS name, 'granted' AS definition
            FROM (
                SELECT CASE WHEN c.relkind = 'S' THEN 'sequence' ELSE 'table' END AS kind, n.nspname || '.' || c.relname AS object, c.relowner AS owner, c.relacl AS acl FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE {$user}
                UNION ALL SELECT 'column', n.nspname || '.' || c.relname || '.' || at.attname, c.relowner, at.attacl FROM pg_attribute at JOIN pg_class c ON c.oid = at.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE at.attacl IS NOT NULL AND {$user}
                UNION ALL SELECT 'routine', n.nspname || '.' || p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')', p.proowner, p.proacl FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE {$user}
                UNION ALL SELECT 'schema', n.nspname, n.nspowner, n.nspacl FROM pg_namespace n WHERE {$user}
                UNION ALL SELECT 'type', n.nspname || '.' || t.typname, t.typowner, t.typacl FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE {$user}
                UNION ALL SELECT 'language', l.lanname, l.lanowner, l.lanacl FROM pg_language l
                UNION ALL SELECT 'database', 'current', d.datdba, d.datacl FROM pg_database d WHERE d.datname = current_database()
                UNION ALL SELECT 'default', coalesce(n.nspname, '*') || '/' || d.defaclobjtype::text, d.defaclrole, d.defaclacl FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace
            ) o CROSS JOIN LATERAL aclexplode(o.acl) a
            WHERE a.grantee <> o.owner
            SQL;
    }

    /** @return array<string, string> */
    private static function byCalls(): array
    {
        $user = self::USER_SCHEMAS;

        return [
            'default' => "SELECT c.relname || '.' || a.attname AS name, pg_get_expr(d.adbin, d.adrelid) AS definition FROM pg_attrdef d JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum JOIN pg_class c ON c.oid = d.adrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE {$user}",
            'check' => "SELECT coalesce(c.relname, ty.typname) || '.' || k.conname AS name, pg_get_constraintdef(k.oid) AS definition FROM pg_constraint k LEFT JOIN pg_class c ON c.oid = k.conrelid LEFT JOIN pg_type ty ON ty.oid = k.contypid JOIN pg_namespace n ON n.oid = k.connamespace WHERE k.contype IN ('c', 'x', 't') AND {$user}",
            'index' => "SELECT n.nspname || '.' || i.relname AS name, pg_get_indexdef(i.oid) AS definition FROM pg_index x JOIN pg_class i ON i.oid = x.indexrelid JOIN pg_namespace n ON n.oid = i.relnamespace WHERE {$user}",
        ];
    }

    private static function text(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
