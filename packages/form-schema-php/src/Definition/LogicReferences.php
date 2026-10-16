<?php

declare(strict_types=1);

namespace FormSchema\Definition;

use FormSchema\Logic\Js;
use FormSchema\Logic\JsonLogic;
use FormSchema\Logic\OperatorNames;

/**
 * Extracts the static `var` paths of a logic expression that resolve against the element's
 * scope, i.e. outside the per-item argument of iteration operators.
 */
final class LogicReferences
{
    /**
     * @return list<string>
     */
    public static function paths(mixed $logic): array
    {
        if (is_array($logic)) {
            return self::merge(array_map(self::paths(...), array_values($logic)));
        }

        if (! JsonLogic::isOperation($logic)) {
            return [];
        }

        [$operator, $args] = JsonLogic::split($logic);

        if ($operator === 'var') {
            $path = self::staticPath($args[0] ?? null);

            return [...($path === null ? [] : [$path]), ...self::paths($args)];
        }

        return self::paths(self::scopedArguments($operator, $args));
    }

    /**
     * Arguments that are evaluated in the element's scope (iteration bodies excluded).
     *
     * @param  list<mixed>  $args
     * @return list<mixed>
     */
    public static function scopedArguments(string $operator, array $args): array
    {
        if (! in_array($operator, OperatorNames::ITERATION, true)) {
            return $args;
        }

        $scoped = [$args[0] ?? null];

        if ($operator === 'reduce' && array_key_exists(2, $args)) {
            $scoped[] = $args[2];
        }

        return $scoped;
    }

    /**
     * Literal path of a `var` (null for computed paths).
     */
    public static function staticPath(mixed $path): ?string
    {
        return match (true) {
            $path === null => '',
            is_string($path) => $path,
            Js::isNumber($path) => Js::toJsString($path),
            default => null,
        };
    }

    /**
     * @param  list<list<string>>  $lists
     * @return list<string>
     */
    private static function merge(array $lists): array
    {
        return $lists === [] ? [] : array_merge(...$lists);
    }
}
