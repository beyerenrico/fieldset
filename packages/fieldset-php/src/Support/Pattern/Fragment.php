<?php

declare(strict_types=1);

namespace Fieldset\Support\Pattern;

/**
 * Translated piece of a pattern.
 */
final readonly class Fragment
{
    /**
     * @param  string  $pcre  PCRE equivalent
     * @param  bool  $complex  contains a quantifier or an alternation (anywhere inside)
     * @param  bool  $group  a capturing or non-capturing group (subject to the nested-quantifier rule)
     */
    public function __construct(
        public string $pcre,
        public bool $complex = false,
        public bool $group = false,
    ) {}

    /**
     * A single code point or a class escape.
     */
    public static function of(int|CodePointSet $value): self
    {
        return new self(is_int($value) ? CodePointSet::single($value)->toPcre() : $value->toPcre());
    }

    /**
     * `\b` / `\B` with the ASCII word characters of ECMAScript.
     */
    public static function boundary(bool $word): self
    {
        $w = '[0-9A-Z_a-z]';

        return new self($word
            ? "(?:(?<={$w})(?!{$w})|(?<!{$w})(?={$w}))"
            : "(?:(?<={$w})(?={$w})|(?<!{$w})(?!{$w}))");
    }
}
