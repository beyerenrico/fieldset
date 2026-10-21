<?php

declare(strict_types=1);

namespace Fieldset\Support\Pattern;

use Fieldset\Support\Text;

/**
 * Recursive-descent parser for the restricted ECMAScript pattern subset of spec §3.4. It rejects
 * everything outside the subset and translates valid patterns to PCRE that needs no Unicode
 * properties: every class is spelled out as code point ranges.
 */
final class PatternParser
{
    private const SYNTAX = ['^', '$', '\\', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|'];

    private const MAX_REPEAT = 1000;

    /** @var list<string> */
    private readonly array $chars;

    private int $pos = 0;

    private function __construct(string $pattern)
    {
        $this->chars = Text::codePoints($pattern);
    }

    /**
     * PCRE body for a valid pattern, or null when the pattern is outside the subset.
     */
    public static function translate(string $pattern): ?string
    {
        if (preg_match('//u', $pattern) !== 1) {
            return null;
        }

        $parser = new self($pattern);

        try {
            $result = $parser->disjunction();

            if ($parser->peek() !== null) {
                throw new InvalidPattern('Unbalanced ")".');
            }

            return $result->pcre;
        } catch (InvalidPattern) {
            return null;
        }
    }

    private function disjunction(): Fragment
    {
        $alternatives = [$this->alternative()];

        while ($this->peek() === '|') {
            $this->pos++;
            $alternatives[] = $this->alternative();
        }

        $complex = count($alternatives) > 1;
        foreach ($alternatives as $alternative) {
            $complex = $complex || $alternative->complex;
        }

        return new Fragment(implode('|', array_map(static fn (Fragment $f): string => $f->pcre, $alternatives)), $complex);
    }

    private function alternative(): Fragment
    {
        $pcre = '';
        $complex = false;

        while (($char = $this->peek()) !== null && $char !== '|' && $char !== ')') {
            $term = $this->term();
            $pcre .= $term->pcre;
            $complex = $complex || $term->complex;
        }

        return new Fragment($pcre, $complex);
    }

    private function term(): Fragment
    {
        $assertion = $this->assertion();

        if ($assertion !== null) {
            return $assertion;
        }

        $atom = $this->atom();
        $quantifier = $this->quantifier();

        if ($quantifier === null) {
            return $atom;
        }

        [$text, $optionalOnly] = $quantifier;

        if ($atom->group && $atom->complex && ! $optionalOnly) {
            throw new InvalidPattern('Nested quantifier.');
        }

        return new Fragment($atom->pcre.$text, true);
    }

    /**
     * `^`, `$`, `\b`, `\B` and lookaheads; none of them may be quantified.
     */
    private function assertion(): ?Fragment
    {
        $char = $this->peek();
        $next = $this->peek(1);

        if ($char === '^' || $char === '$') {
            $this->pos++;

            return new Fragment($char === '^' ? '^' : '\z');
        }

        if ($char === '\\' && ($next === 'b' || $next === 'B')) {
            $this->pos += 2;

            return Fragment::boundary($next === 'b');
        }

        if ($char === '(' && $next === '?' && in_array($this->peek(2), ['=', '!'], true)) {
            $kind = $this->peek(2);
            $this->pos += 3;
            $inner = $this->disjunction();
            $this->expect(')');

            return new Fragment('(?'.$kind.$inner->pcre.')', $inner->complex);
        }

        return null;
    }

    private function atom(): Fragment
    {
        $char = $this->next();

        return match (true) {
            $char === '.' => new Fragment(CodePointSet::dot()->toPcre()),
            $char === '\\' => Fragment::of($this->escape(false)),
            $char === '[' => $this->characterClass(),
            $char === '(' => $this->group(),
            in_array($char, self::SYNTAX, true) => throw new InvalidPattern('Unexpected "'.$char.'".'),
            default => Fragment::of(Text::ord($char)),
        };
    }

    private function group(): Fragment
    {
        $prefix = '(';

        if ($this->peek() === '?') {
            if ($this->peek(1) !== ':') {
                throw new InvalidPattern('Unsupported group.');
            }

            $this->pos += 2;
            $prefix = '(?:';
        }

        $inner = $this->disjunction();
        $this->expect(')');

        return new Fragment($prefix.$inner->pcre.')', $inner->complex, true);
    }

    /**
     * @return array{string, bool}|null PCRE text and whether it is `?`/`{0,1}`
     */
    private function quantifier(): ?array
    {
        $char = $this->peek();

        if ($char === null || ! in_array($char, ['*', '+', '?', '{'], true)) {
            return null;
        }

        [$min, $max] = $char === '{' ? $this->braces() : $this->symbolBounds($char);
        $text = sprintf('{%d,%s}', $min, $max === null ? '' : (string) $max);
        $lazy = $this->peek() === '?';
        $this->pos += $lazy ? 1 : 0;

        return [$text.($lazy ? '?' : ''), $min === 0 && $max === 1];
    }

    /**
     * @return array{int, int|null}
     */
    private function symbolBounds(string $char): array
    {
        $this->pos++;

        return match ($char) {
            '*' => [0, null],
            '+' => [1, null],
            default => [0, 1],
        };
    }

    /**
     * `{n}`, `{n,}` or `{n,m}` with n ≤ m ≤ 1000.
     *
     * @return array{int, int|null}
     */
    private function braces(): array
    {
        $this->expect('{');
        $min = $this->number();
        $max = $min;

        if ($this->peek() === ',') {
            $this->pos++;
            $max = $this->peek() === '}' ? null : $this->number();
        }

        $this->expect('}');

        if ($max !== null && $min > $max) {
            throw new InvalidPattern('Reversed quantifier bounds.');
        }

        return [$min, $max];
    }

    private function number(): int
    {
        $digits = '';

        while (($char = $this->peek()) !== null && ctype_digit($char)) {
            $digits .= $char;
            $this->pos++;
        }

        $value = ltrim($digits, '0');

        if ($digits === '' || strlen($value) > 4 || (int) $value > self::MAX_REPEAT) {
            throw new InvalidPattern('Invalid quantifier bound.');
        }

        return (int) $value;
    }

    private function characterClass(): Fragment
    {
        $negate = $this->peek() === '^';
        $this->pos += $negate ? 1 : 0;
        $tokens = [];

        while (($char = $this->next()) !== ']') {
            $tokens[] = match ($char) {
                '\\' => $this->escape(true),
                '-' => '-',
                '[' => throw new InvalidPattern('Unescaped "[" inside a class.'),
                default => Text::ord($char),
            };
        }

        $set = ClassBuilder::build($tokens);

        return new Fragment(($negate ? $set->complement() : $set)->toPcre());
    }

    /**
     * An escape after the backslash: a code point or a class escape.
     */
    private function escape(bool $inClass): int|CodePointSet
    {
        $char = $this->next();

        return match ($char) {
            'd' => CodePointSet::digit(),
            'D' => CodePointSet::digit()->complement(),
            'w' => CodePointSet::word(),
            'W' => CodePointSet::word()->complement(),
            's' => CodePointSet::space(),
            'S' => CodePointSet::space()->complement(),
            't' => 0x09, 'n' => 0x0A, 'v' => 0x0B, 'f' => 0x0C, 'r' => 0x0D,
            '0' => $this->nul(),
            'x' => $this->hex(2),
            'u' => $this->unicode(),
            '/', '-' => Text::ord($char),
            default => in_array($char, self::SYNTAX, true)
                ? Text::ord($char)
                : throw new InvalidPattern(($inClass ? 'Class escape' : 'Escape').' \\'.$char.' is not allowed.'),
        };
    }

    private function nul(): int
    {
        $next = $this->peek();

        if ($next !== null && ctype_digit($next)) {
            throw new InvalidPattern('Octal escapes are not allowed.');
        }

        return 0;
    }

    private function hex(int $length): int
    {
        $digits = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $this->next();

            if (! ctype_xdigit($char)) {
                throw new InvalidPattern('Invalid hexadecimal escape.');
            }

            $digits .= $char;
        }

        return (int) hexdec($digits);
    }

    /**
     * `\uHHHH` (a surrogate pair of two escapes is one code point) or `\u{H…}` up to 10FFFF.
     */
    private function unicode(): int
    {
        if ($this->peek() !== '{') {
            $value = $this->hex(4);

            return $value >= 0xD800 && $value <= 0xDBFF ? $this->lowSurrogate($value) : $value;
        }

        $this->pos++;
        $digits = '';

        while (($char = $this->next()) !== '}') {
            if (! ctype_xdigit($char)) {
                throw new InvalidPattern('Invalid \u{…} escape.');
            }

            $digits .= $char;
        }

        $value = ltrim($digits, '0');

        if ($digits === '' || strlen($value) > 6 || hexdec($value === '' ? '0' : $value) > CodePointSet::MAX) {
            throw new InvalidPattern('Code point out of range.');
        }

        return (int) hexdec($value === '' ? '0' : $value);
    }

    private function lowSurrogate(int $high): int
    {
        $start = $this->pos;

        if ($this->peek() === '\\' && $this->peek(1) === 'u') {
            $this->pos += 2;
            $digits = implode('', array_slice($this->chars, $this->pos, 4));

            if (strlen($digits) === 4 && ctype_xdigit($digits) && hexdec($digits) >= 0xDC00 && hexdec($digits) <= 0xDFFF) {
                $this->pos += 4;

                return 0x10000 + (($high - 0xD800) << 10) + ((int) hexdec($digits) - 0xDC00);
            }
        }

        $this->pos = $start;

        return $high;
    }

    private function peek(int $offset = 0): ?string
    {
        return $this->chars[$this->pos + $offset] ?? null;
    }

    private function next(): string
    {
        $char = $this->peek() ?? throw new InvalidPattern('Unexpected end of pattern.');
        $this->pos++;

        return $char;
    }

    private function expect(string $char): void
    {
        if ($this->next() !== $char) {
            throw new InvalidPattern('Expected "'.$char.'".');
        }
    }
}
