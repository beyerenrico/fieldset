<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\Connection;
use stdClass;

/**
 * The schema check of an unauthenticated archive (plan U17, KTD18, ADR 0085 amendment after review): before the restored
 * database is handed to the superuser, every object that carries code must be one this image's migrations create, with
 * the same definition. Compared are functions (outside extensions), triggers, column defaults and generated columns,
 * indexes (expressions included), check constraints, rules, policies, views, event triggers, schemas, extensions and
 * domain or enum types, each by name and by the definition Postgres prints for it. The restored database may lack
 * objects (an older backup), never have other or changed ones. The catalog functions used only print definitions; no
 * restored code runs.
 */
final readonly class SchemaAudit
{
    private const string USER_SCHEMAS = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND n.nspname NOT LIKE 'pg_temp_%' AND n.nspname NOT LIKE 'pg_toast_temp_%'";

    /** @return list<string> objects of `$restored` that `$reference` does not have with the same definition (at most 50) */
    public static function mismatches(Connection $restored, Connection $reference): array
    {
        $expected = self::objects($reference);
        $problems = [];
        foreach (self::objects($restored) as $key => $definition) {
            if (! array_key_exists($key, $expected)) {
                $problems[] = "unknown {$key}";
            } elseif ($expected[$key] !== $definition) {
                $problems[] = "changed {$key}";
            }
        }

        return array_slice($problems, 0, 50);
    }

    /** @return array<string, string> kind:name => definition */
    public static function objects(Connection $db): array
    {
        $objects = [];
        foreach (self::queries() as $kind => $sql) {
            foreach ($db->select($sql) as $row) {
                $objects[$kind.':'.self::text($row, 'name')] = self::text($row, 'definition');
            }
        }
        ksort($objects);

        return $objects;
    }

    /** @return array<string, string> */
    private static function queries(): array
    {
        $user = self::USER_SCHEMAS;
        $notExtension = "NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')";

        return [
            'function' => "SELECT n.nspname || '.' || p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')' AS name, md5(pg_get_functiondef(p.oid)) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE {$user} AND p.prokind <> 'a' AND {$notExtension}",
            'aggregate' => "SELECT n.nspname || '.' || p.proname AS name, 'aggregate' AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE {$user} AND p.prokind = 'a' AND {$notExtension}",
            'trigger' => "SELECT c.relname || '.' || t.tgname AS name, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND {$user}",
            'default' => "SELECT c.relname || '.' || a.attname AS name, a.attgenerated::text || pg_get_expr(d.adbin, d.adrelid) AS definition FROM pg_attrdef d JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum JOIN pg_class c ON c.oid = d.adrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE {$user}",
            'index' => "SELECT n.nspname || '.' || i.relname AS name, pg_get_indexdef(i.oid) AS definition FROM pg_index x JOIN pg_class i ON i.oid = x.indexrelid JOIN pg_namespace n ON n.oid = i.relnamespace WHERE {$user}",
            'check' => "SELECT coalesce(c.relname, ty.typname) || '.' || k.conname AS name, pg_get_constraintdef(k.oid) AS definition FROM pg_constraint k LEFT JOIN pg_class c ON c.oid = k.conrelid LEFT JOIN pg_type ty ON ty.oid = k.contypid JOIN pg_namespace n ON n.oid = k.connamespace WHERE k.contype IN ('c', 'x', 't') AND {$user}",
            'rule' => "SELECT r.tablename || '.' || r.rulename AS name, r.definition AS definition FROM pg_rules r WHERE r.schemaname NOT IN ('pg_catalog', 'information_schema')",
            'policy' => "SELECT p.tablename || '.' || p.policyname AS name, coalesce(p.qual, '') || '|' || coalesce(p.with_check, '') AS definition FROM pg_policies p",
            'view' => "SELECT n.nspname || '.' || c.relname AS name, c.relkind::text || pg_get_viewdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('v', 'm') AND {$user}",
            'event_trigger' => 'SELECT evtname AS name, evtevent AS definition FROM pg_event_trigger',
            'schema' => "SELECT n.nspname AS name, 'schema' AS definition FROM pg_namespace n WHERE {$user}",
            'extension' => 'SELECT extname AS name, extversion AS definition FROM pg_extension',
            'type' => "SELECT n.nspname || '.' || t.typname AS name, t.typtype::text || coalesce(pg_catalog.format_type(t.typbasetype, t.typtypmod), '') AS definition FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE t.typtype IN ('d', 'e', 'r', 'm') AND {$user} AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = t.oid AND d.deptype = 'e')",
        ];
    }

    private static function text(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
