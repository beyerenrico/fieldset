<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

/**
 * "Newer" and "older" for a restore (KTD18, AE8, plan U17, ADR 0085): decided by comparing migration sets, read from the
 * dump's own `migrations` table (pg_restore prints it without a database), never from a manifest an unauthenticated
 * archive could forge. A backup that ran a migration this image does not ship is newer (exit 7); one that lacks
 * migrations of this image is older.
 */
final readonly class MigrationSet
{
    public const string SAME = 'same';

    public const string OLDER = 'older';

    public const string NEWER = 'newer';

    /** The dump has no migrations table: not a dump of this app. */
    public const string NONE = 'none';

    /**
     * @param  list<string>|null  $backup
     * @param  list<string>  $image
     * @return array{relation: string, count: int, last: string|null, unknown: list<string>, pending: int}
     */
    public static function compare(?array $backup, array $image): array
    {
        if ($backup === null || $backup === []) {
            return [...self::unknown(), 'relation' => self::NONE];
        }
        $unknown = array_values(array_diff($backup, $image));
        $pending = count(array_diff($image, $backup));

        return [
            'relation' => $unknown !== [] ? self::NEWER : ($pending > 0 ? self::OLDER : self::SAME),
            'count' => count($backup), 'last' => $backup[array_key_last($backup)], 'unknown' => array_slice($unknown, 0, 20), 'pending' => $pending,
        ];
    }

    /** @return array{relation: string, count: int, last: string|null, unknown: list<string>, pending: int} */
    public static function unknown(): array
    {
        return ['relation' => self::NONE, 'count' => 0, 'last' => null, 'unknown' => [], 'pending' => 0];
    }

    /**
     * The `migration` column of the COPY block pg_restore prints for the migrations table.
     *
     * @return list<string>|null
     */
    public static function fromCopy(string $sql): ?array
    {
        if (preg_match('/^COPY (?:public\.)?migrations \(([^)]*)\) FROM stdin;\n(.*?)^\\\\\.$/msD', $sql, $match) !== 1) {
            return null;
        }
        $column = array_search('migration', array_map(trim(...), explode(',', $match[1])), true);
        if ($column === false) {
            return null;
        }
        $names = [];
        foreach (explode("\n", rtrim($match[2], "\n")) as $row) {
            $name = explode("\t", $row)[$column] ?? '';
            if (preg_match('/^[A-Za-z0-9_]{1,200}$/D', $name) === 1) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /** @return list<string> the migration names this image ships (database/migrations, without `.php`) */
    public static function image(string $directory): array
    {
        $names = array_map(static fn (string $file): string => basename($file, '.php'), glob($directory.'/*.php') ?: []);
        sort($names);

        return $names;
    }
}
