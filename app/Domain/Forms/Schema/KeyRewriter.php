<?php

declare(strict_types=1);

namespace App\Domain\Forms\Schema;

use stdClass;

/**
 * Renames keys inside JsonLogic expressions and key-valued properties (used when a container is
 * cloned and its children get new keys, so copied calculations reference the copies).
 */
final class KeyRewriter
{
    private const array LOGIC_PROPERTIES = ['visibleIf', 'requiredIf', 'readOnlyIf', 'default', 'expression'];

    private const array ROW_KEY_OPERATORS = ['sum', 'min', 'max'];

    /** @param  array<string, string>  $renames  old key => new key */
    public static function element(stdClass $element, array $renames): void
    {
        if ($renames === []) {
            return;
        }

        foreach (self::LOGIC_PROPERTIES as $property) {
            if (property_exists($element, $property)) {
                $element->{$property} = self::logic($element->{$property}, $renames);
            }
        }

        if (is_string($element->signerNameFrom ?? null) && isset($renames[$element->signerNameFrom])) {
            $element->signerNameFrom = $renames[$element->signerNameFrom];
        }
    }

    /** @param  array<string, string>  $renames */
    public static function logic(mixed $node, array $renames): mixed
    {
        if (is_array($node)) {
            return array_map(static fn (mixed $child): mixed => self::logic($child, $renames), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        $copy = new stdClass;
        foreach (get_object_vars($node) as $operator => $args) {
            $copy->{$operator} = match (true) {
                $operator === 'var' => self::var($args, $renames),
                in_array($operator, self::ROW_KEY_OPERATORS, true) && is_array($args) => self::rowKeyArgs(array_values($args), $renames),
                default => self::logic($args, $renames),
            };
        }

        return $copy;
    }

    /** @param  array<string, string>  $renames */
    private static function var(mixed $args, array $renames): mixed
    {
        if (is_string($args)) {
            return self::path($args, $renames);
        }

        if (is_array($args) && is_string($args[0] ?? null)) {
            $args[0] = self::path($args[0], $renames);

            return array_map(static fn (mixed $a, int $i): mixed => $i === 0 ? $a : self::logic($a, $renames), $args, array_keys($args));
        }

        return self::logic($args, $renames);
    }

    /**
     * @param  list<mixed>  $args
     * @param  array<string, string>  $renames
     * @return list<mixed>
     */
    private static function rowKeyArgs(array $args, array $renames): array
    {
        $rewritten = array_map(static fn (mixed $a): mixed => self::logic($a, $renames), $args);
        if (count($args) === 2 && is_string($args[1]) && isset($renames[$args[1]])) {
            $rewritten[1] = $renames[$args[1]];
        }

        return array_values($rewritten);
    }

    /** @param  array<string, string>  $renames */
    private static function path(string $path, array $renames): string
    {
        $prefix = str_starts_with($path, '$root.') ? '$root.' : '';
        $rest = substr($path, strlen($prefix));
        $segments = explode('.', $rest);

        if (isset($renames[$segments[0]])) {
            $segments[0] = $renames[$segments[0]];
        }

        return $prefix.implode('.', $segments);
    }
}
