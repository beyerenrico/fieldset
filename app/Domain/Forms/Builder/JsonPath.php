<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use stdClass;

/** Get/set/unset by property path on stdClass trees (mutates only the tree it is given). */
final class JsonPath
{
    /** Sentinel for "property not present". */
    public const string ABSENT = "\0absent";

    /** @param  list<string>  $path */
    public static function get(stdClass $node, array $path): mixed
    {
        $current = $node;
        foreach ($path as $segment) {
            if (! $current instanceof stdClass || ! property_exists($current, $segment)) {
                return self::ABSENT;
            }
            $current = $current->{$segment};
        }

        return $current;
    }

    /** @param  list<string>  $path */
    public static function set(stdClass $node, array $path, mixed $value): void
    {
        $last = array_pop($path);
        $current = $node;
        foreach ($path as $segment) {
            if (! ($current->{$segment} ?? null) instanceof stdClass) {
                $current->{$segment} = new stdClass;
            }
            $current = $current->{$segment};
        }
        $current->{(string) $last} = $value;
    }

    /**
     * Removes a property; parent objects left empty are removed unless they existed in $original.
     *
     * @param  list<string>  $path
     */
    public static function unset(stdClass $node, array $path, stdClass $original): void
    {
        $parents = [];
        $current = $node;
        foreach (array_slice($path, 0, -1) as $segment) {
            if (! ($current->{$segment} ?? null) instanceof stdClass) {
                return;
            }
            $parents[] = [$current, $segment];
            $current = $current->{$segment};
        }
        unset($current->{(string) end($path)});

        for ($i = count($parents) - 1; $i >= 0; $i--) {
            [$parent, $segment] = $parents[$i];
            $prefix = array_slice($path, 0, $i + 1);
            if (get_object_vars($parent->{$segment}) !== [] || self::get($original, $prefix) !== self::ABSENT) {
                return;
            }
            unset($parent->{$segment});
        }
    }
}
