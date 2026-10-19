<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\Connection;
use stdClass;

/**
 * The schema check of an unauthenticated archive (plan U17, KTD18, ADR 0085 amendment after review): before the restored
 * database is handed to the superuser, every object that carries code must be one this image's migrations create.
 *
 * - Exactly, by name and printed definition: functions (outside extensions), aggregates, triggers, rules, policies,
 *   views, event triggers, schemas, extensions, operators, casts and domain or enum types.
 * - By name and by the functions their expressions call: column defaults (generated columns included), check
 *   constraints and indexes. Postgres prints a re-parsed expression differently after a dump and restore (`IN (…)`
 *   becomes `= ANY (ARRAY[…])` with other casts), so their text cannot be compared, and it records no dependency on
 *   built-in functions; what they call can be compared: every `name(` of the printed expression (string literals left
 *   out) must be one the reference's own expressions use, so `pg_read_file(…)` in a default is refused.
 *
 * The restored database may lack objects (an older backup), never have other or changed ones. The catalog functions used
 * only print definitions and read dependencies; no restored code runs.
 */
final readonly class SchemaAudit
{
    private const string USER_SCHEMAS = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND n.nspname NOT LIKE 'pg_temp_%' AND n.nspname NOT LIKE 'pg_toast_temp_%'";

    /** @return list<string> objects of `$restored` that `$reference` does not have with the same definition (at most 50) */
    public static function mismatches(Connection $restored, Connection $reference): array
    {
        $problems = [];
        $expected = self::objects($reference);
        foreach (self::objects($restored) as $key => $definition) {
            if (! array_key_exists($key, $expected)) {
                $problems[] = "unknown {$key}";
            } elseif ($expected[$key] !== $definition) {
                $problems[] = "changed {$key}";
            }
        }
        $known = self::expressions($reference);
        $allowed = array_values(array_unique(array_merge([], ...array_values($known))));
        foreach (self::expressions($restored) as $key => $calls) {
            if (! array_key_exists($key, $known)) {
                $problems[] = "unknown {$key}";

                continue;
            }
            foreach (array_diff($calls, $allowed) as $call) {
                $problems[] = "{$key} calls {$call}()";
            }
        }

        return array_slice($problems, 0, 50);
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
            'trigger' => "SELECT c.relname || '.' || t.tgname AS name, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND {$user}",
            'rule' => "SELECT r.tablename || '.' || r.rulename AS name, r.definition AS definition FROM pg_rules r WHERE r.schemaname NOT IN ('pg_catalog', 'information_schema')",
            'policy' => "SELECT p.tablename || '.' || p.policyname AS name, coalesce(p.qual, '') || '|' || coalesce(p.with_check, '') AS definition FROM pg_policies p",
            'view' => "SELECT n.nspname || '.' || c.relname AS name, c.relkind::text || pg_get_viewdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('v', 'm') AND {$user}",
            'event_trigger' => 'SELECT evtname AS name, evtevent AS definition FROM pg_event_trigger',
            'schema' => "SELECT n.nspname AS name, 'schema' AS definition FROM pg_namespace n WHERE {$user}",
            'extension' => 'SELECT extname AS name, extversion AS definition FROM pg_extension',
            'operator' => "SELECT n.nspname || '.' || o.oprname || '(' || o.oprleft::regtype || ',' || o.oprright::regtype || ')' AS name, o.oprcode::text AS definition FROM pg_operator o JOIN pg_namespace n ON n.oid = o.oprnamespace WHERE {$user} AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = o.oid AND d.deptype = 'e')",
            'cast' => "SELECT k.castsource::regtype || '->' || k.casttarget::regtype AS name, k.castfunc::text AS definition FROM pg_cast k WHERE k.oid >= 16384 AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = k.oid AND d.deptype = 'e')",
            'type' => "SELECT n.nspname || '.' || t.typname AS name, t.typtype::text || coalesce(pg_catalog.format_type(t.typbasetype, t.typtypmod), '') AS definition FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE t.typtype IN ('d', 'e', 'r', 'm') AND {$user} AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = t.oid AND d.deptype = 'e')",
        ];
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
