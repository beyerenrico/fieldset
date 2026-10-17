<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Forms\Schema\KeyRewriter;
use App\Domain\Forms\Support\BlankDefinition;
use stdClass;

/**
 * Clones an element or a page with its subtree (BUILD_PROMPT §8.2 "cloning regenerates id and suffixes
 * key", ADR 0037): every node (columns included) gets a fresh UUIDv7, every key in the target data
 * scope gets a `_copy`, `_copy2`, … suffix that is unique there, and references inside the clone follow
 * the renamed keys. A repeater inside the clone opens its own row scope, so its rows keep their keys.
 * The original keys always count as taken: a copy never repeats the key of what it was copied from.
 */
final class ElementCloner
{
    private const int MAX_KEY_LENGTH = 64;

    /** @param  list<string>  $scopeKeys  keys already used in the target scope (for a page: element and page keys) */
    public static function clone(stdClass $node, array $scopeKeys): stdClass
    {
        $clone = DefinitionTools::copy($node);
        $used = array_fill_keys([...$scopeKeys, ...self::scopeKeysOf($clone)], true);
        $renames = [];
        $shadowed = [];
        self::renew($clone, $used, $renames, $shadowed, null);
        foreach ($shadowed as [$target, $rowKeys]) {
            KeyRewriter::element($target, array_diff_key($renames, $rowKeys));
        }

        return $clone;
    }

    /**
     * Fresh ids, unique keys in the scope, and the nodes to rewrite with the row keys that shadow them.
     *
     * @param  array<string, true>  $used
     * @param  array<string, string>  $renames
     * @param  list<array{stdClass, array<string, true>}>  $nodes
     * @param  array<string, true>|null  $rowKeys  keys of the enclosing cloned repeater rows (null: target scope)
     */
    private static function renew(stdClass $node, array &$used, array &$renames, array &$nodes, ?array $rowKeys): void
    {
        if (property_exists($node, 'id')) {
            $node->id = BlankDefinition::id();
        }
        if ($rowKeys === null && is_string($node->key ?? null)) {
            $key = self::unique($node->key, $used);
            $used[$key] = true;
            if ($key !== $node->key) {
                $renames[$node->key] = $key;
                $node->key = $key;
            }
        }
        $nodes[] = [$node, $rowKeys ?? []];

        $childRows = ($node->type ?? null) === 'repeater' ? [...($rowKeys ?? []), ...array_fill_keys(self::childKeys($node), true)] : $rowKeys;
        foreach (self::children($node) as $child) {
            self::renew($child, $used, $renames, $nodes, $childRows);
        }
        foreach (is_array($node->columns ?? null) ? $node->columns : [] as $column) {
            if ($column instanceof stdClass && property_exists($column, 'id')) {
                $column->id = BlankDefinition::id();
            }
            foreach ($column instanceof stdClass ? self::children($column) : [] as $child) {
                self::renew($child, $used, $renames, $nodes, $rowKeys);
            }
        }
    }

    /** @param  array<string, true>  $used */
    private static function unique(string $key, array $used): string
    {
        if (! isset($used[$key])) {
            return $key;
        }
        $base = (string) preg_replace('/_copy\d*$/', '', $key);
        $base = $base !== '' ? $base : $key;
        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? '_copy' : '_copy'.$n;
            $candidate = substr($base, 0, self::MAX_KEY_LENGTH - strlen($suffix)).$suffix;
            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }
    }

    /**
     * Keys of the clone that live in the target scope (the node, and its descendants outside repeater rows).
     *
     * @return list<string>
     */
    private static function scopeKeysOf(stdClass $node): array
    {
        $keys = is_string($node->key ?? null) ? [$node->key] : [];
        if (($node->type ?? null) === 'repeater') {
            return $keys;
        }
        $containers = [$node, ...array_filter(is_array($node->columns ?? null) ? $node->columns : [], fn (mixed $c): bool => $c instanceof stdClass)];
        foreach ($containers as $container) {
            foreach (self::children($container) as $child) {
                $keys = [...$keys, ...self::scopeKeysOf($child)];
            }
        }

        return $keys;
    }

    /** @return list<string> keys of a repeater's row scope */
    private static function childKeys(stdClass $repeater): array
    {
        $keys = [];
        foreach (self::children($repeater) as $child) {
            $keys = [...$keys, ...self::scopeKeysOf($child)];
        }

        return $keys;
    }

    /** @return list<stdClass> */
    private static function children(stdClass $container): array
    {
        return array_values(array_filter(is_array($container->elements ?? null) ? $container->elements : [], fn (mixed $e): bool => $e instanceof stdClass));
    }
}
