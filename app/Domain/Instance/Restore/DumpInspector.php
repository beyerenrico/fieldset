<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

/**
 * Judges a dump by its table of contents (`pg_restore --list`) before `pg_restore` runs (R41, KTD18, plan U17, ADR 0085).
 * A backup of this app holds only the object types its migrations create, in schema `public`: tables with their data,
 * sequences, defaults, constraints, indexes, triggers, functions, comments and one extension, `pg_trgm` (a trusted
 * extension the explorer search index needs). Anything else refuses the dump: another extension (it would run code as
 * the restoring role), an event trigger, a rule, a policy, a foreign server, a large object, another schema, or a type
 * the reader does not know.
 *
 * An archive that failed or skipped the HMAC (`$strict`) must also name only the app's own routines and triggers: it is
 * restored by a role that owns nothing but the scratch database, and these names are the ones the app later calls.
 */
final readonly class DumpInspector
{
    /** What pg_dump writes for this app's schema. */
    public const array OBJECT_TYPES = [
        'TABLE', 'TABLE DATA', 'SEQUENCE', 'SEQUENCE SET', 'SEQUENCE OWNED BY', 'DEFAULT', 'CONSTRAINT', 'FK CONSTRAINT',
        'INDEX', 'TRIGGER', 'FUNCTION', 'COMMENT', 'EXTENSION',
    ];

    public const array EXTENSIONS = ['pg_trgm'];

    /** The routines and triggers the migrations create (DumpInspectorTest keeps both lists in step with them). */
    public const array ROUTINES = [
        'activity_log_append_only', 'activity_log_no_truncate', 'audit_chain_epochs_append_only', 'audit_prunable', 'audit_prune',
        'audit_seal', 'design_versions_immutable', 'form_versions_immutable', 'renditions_immutable',
    ];

    public const array TRIGGERS = [
        'activity_log_append_only_trg', 'activity_log_no_truncate_trg', 'audit_chain_epochs_append_only',
        'design_versions_immutable_trg', 'form_versions_immutable_trg', 'renditions_immutable_trg',
    ];

    /** Every multi-word type pg_dump writes, so a refused one is named correctly (longest first). */
    private const array KNOWN_TYPES = [
        'MATERIALIZED VIEW DATA', 'TEXT SEARCH CONFIGURATION', 'TEXT SEARCH DICTIONARY', 'TEXT SEARCH TEMPLATE', 'TEXT SEARCH PARSER',
        'FOREIGN DATA WRAPPER', 'PROCEDURAL LANGUAGE', 'SEQUENCE OWNED BY', 'MATERIALIZED VIEW', 'PUBLICATION TABLE',
        'PUBLICATION TABLES IN SCHEMA', 'OPERATOR FAMILY', 'OPERATOR CLASS', 'FOREIGN SERVER', 'FOREIGN TABLE', 'EVENT TRIGGER',
        'SECURITY LABEL', 'ACCESS METHOD', 'FK CONSTRAINT', 'INDEX ATTACH', 'ROW SECURITY', 'SEQUENCE SET', 'USER MAPPING',
        'LARGE OBJECT', 'DEFAULT ACL', 'SHELL TYPE', 'TABLE DATA', 'TABLE ATTACH', 'BLOB METADATA',
    ];

    /**
     * @return array{entries: int, types: array<string, int>, rejected: list<string>}
     */
    public static function inspect(string $listing, bool $strict): array
    {
        $types = [];
        $rejected = [];
        $entries = 0;
        foreach (explode("\n", $listing) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }
            $entries++;
            $entry = self::parse($line);
            $types[$entry['type']] = ($types[$entry['type']] ?? 0) + 1;
            $problem = $entry['type'] === '?' ? 'an entry that cannot be read' : self::problem($entry, $strict);
            if ($problem !== null) {
                $rejected[] = $problem.': '.mb_strimwidth($line, 0, 200, '…');
            }
        }
        ksort($types);

        return ['entries' => $entries, 'types' => $types, 'rejected' => $rejected];
    }

    /** @return array{type: string, schema: string, rest: list<string>} */
    private static function parse(string $line): array
    {
        if (preg_match('/^\d+; \d+ \d+ (.+)$/D', $line, $match) !== 1) {
            return ['type' => '?', 'schema' => '', 'rest' => []];
        }
        $body = $match[1];
        $type = null;
        foreach ([...self::KNOWN_TYPES, ...self::OBJECT_TYPES] as $known) {
            if (str_starts_with($body, $known.' ')) {
                $type = $known;

                break;
            }
        }
        $type ??= strtok($body, ' ') ?: '?';
        $words = explode(' ', trim(substr($body, strlen($type))));

        return ['type' => $type, 'schema' => $words[0] ?? '', 'rest' => array_slice($words, 1)];
    }

    /** @param  array{type: string, schema: string, rest: list<string>}  $entry */
    private static function problem(array $entry, bool $strict): ?string
    {
        if (! in_array($entry['type'], self::OBJECT_TYPES, true)) {
            return "an object type a backup of this app never has ({$entry['type']})";
        }
        if ($entry['type'] === 'EXTENSION') {
            return in_array($entry['rest'][0] ?? '', self::EXTENSIONS, true) ? null : 'an extension other than '.implode(', ', self::EXTENSIONS);
        }
        if (! in_array($entry['schema'], ['public', '-'], true)) {
            return 'an object outside schema public';
        }

        return $strict ? self::strictProblem($entry) : null;
    }

    /** @param  array{type: string, schema: string, rest: list<string>}  $entry */
    private static function strictProblem(array $entry): ?string
    {
        return match ($entry['type']) {
            'FUNCTION' => in_array(strtok($entry['rest'][0] ?? '', '('), self::ROUTINES, true) ? null : 'a function the app does not define',
            'TRIGGER' => in_array($entry['rest'][1] ?? '', self::TRIGGERS, true) ? null : 'a trigger the app does not define',
            default => null,
        };
    }
}
