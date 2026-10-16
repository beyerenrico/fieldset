<?php

declare(strict_types=1);

namespace FormSchema\Support\Pattern;

/**
 * An immutable set of Unicode code points as sorted, merged ranges.
 */
final readonly class CodePointSet
{
    public const MAX = 0x10FFFF;

    /** ECMAScript WhiteSpace and LineTerminator (spec §3.4). */
    private const SPACE = [
        [0x09, 0x0D], [0x20, 0x20], [0xA0, 0xA0], [0x1680, 0x1680], [0x2000, 0x200A],
        [0x2028, 0x2029], [0x202F, 0x202F], [0x205F, 0x205F], [0x3000, 0x3000], [0xFEFF, 0xFEFF],
    ];

    /** ASCII word characters: [0-9A-Z_a-z]. */
    private const WORD = [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]];

    /**
     * @param  list<array{int, int}>  $ranges  sorted, non-overlapping, non-adjacent
     */
    private function __construct(public array $ranges) {}

    /**
     * @param  list<array{int, int}>  $ranges
     */
    public static function of(array $ranges): self
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($ranges as [$low, $high]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $low <= $merged[$last][1] + 1) {
                $merged[$last] = [$merged[$last][0], max($merged[$last][1], $high)];

                continue;
            }

            $merged[] = [$low, $high];
        }

        return new self($merged);
    }

    public static function single(int $codePoint): self
    {
        return new self([[$codePoint, $codePoint]]);
    }

    public static function digit(): self
    {
        return new self([[0x30, 0x39]]);
    }

    public static function word(): self
    {
        return new self(self::WORD);
    }

    public static function space(): self
    {
        return new self(self::SPACE);
    }

    /**
     * `.`: every code point except \n, \r, U+2028 and U+2029.
     */
    public static function dot(): self
    {
        return self::of([[0x0A, 0x0A], [0x0D, 0x0D], [0x2028, 0x2029]])->complement();
    }

    public function union(self $other): self
    {
        return self::of([...$this->ranges, ...$other->ranges]);
    }

    public function complement(): self
    {
        $ranges = [];
        $next = 0;

        foreach ($this->ranges as [$low, $high]) {
            if ($low > $next) {
                $ranges[] = [$next, $low - 1];
            }

            $next = $high + 1;
        }

        if ($next <= self::MAX) {
            $ranges[] = [$next, self::MAX];
        }

        return new self($ranges);
    }

    /**
     * PCRE (UTF mode) for the set. Surrogates never occur in UTF-8 text and are left out; an empty
     * set becomes a group that never matches.
     */
    public function toPcre(): string
    {
        $parts = [];

        foreach ($this->withoutSurrogates() as [$low, $high]) {
            $parts[] = $low === $high ? sprintf('\x{%X}', $low) : sprintf('\x{%X}-\x{%X}', $low, $high);
        }

        return $parts === [] ? '(?:(?!))' : '['.implode('', $parts).']';
    }

    /**
     * @return list<array{int, int}>
     */
    private function withoutSurrogates(): array
    {
        $ranges = [];

        foreach ($this->ranges as [$low, $high]) {
            if ($low < 0xD800) {
                $ranges[] = [$low, min($high, 0xD7FF)];
            }

            if ($high > 0xDFFF) {
                $ranges[] = [max($low, 0xE000), $high];
            }
        }

        return $ranges;
    }
}
