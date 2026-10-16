<?php

declare(strict_types=1);

namespace FormSchema\Support\Pattern;

/**
 * Builds the code point set of a character class body (ECMAScript `u`-mode ClassRanges):
 * `a-z` ranges need single code points on both sides in order; a `-` that cannot form a range
 * is a literal.
 */
final class ClassBuilder
{
    /**
     * @param  list<int|CodePointSet|string>  $tokens  code points, class escapes, and '-' for an unescaped dash
     */
    public static function build(array $tokens): CodePointSet
    {
        $set = CodePointSet::of([]);
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            if ($i + 2 < $count && $tokens[$i + 1] === '-') {
                $low = self::endpoint($tokens[$i]);
                $high = self::endpoint($tokens[$i + 2]);

                if ($low > $high) {
                    throw new InvalidPattern('Class range out of order.');
                }

                $set = $set->union(CodePointSet::of([[$low, $high]]));
                $i += 3;

                continue;
            }

            $token = $tokens[$i];
            $set = $set->union($token instanceof CodePointSet ? $token : CodePointSet::single(self::endpoint($token)));
            $i++;
        }

        return $set;
    }

    private static function endpoint(int|CodePointSet|string $token): int
    {
        if ($token instanceof CodePointSet) {
            throw new InvalidPattern('A class escape cannot bound a range.');
        }

        return is_int($token) ? $token : 0x2D;
    }
}
