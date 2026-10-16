<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Qpdf;

use App\Domain\Forms\Import\Model\TextRun;

/**
 * Best-effort extraction of positioned text from a page content stream (label detection only).
 * Understands the text operators (BT/ET, Tf, Td, TD, Tm, T*, TL, Tj, TJ, ', ") and q/Q/cm.
 * Simple-font bytes are decoded as WinAnsi; text in composite (Type0) fonts is skipped. Positions
 * and widths are approximations: glyph widths are estimated, not read from the font.
 */
final class ContentStreamText
{
    private const int MAX_RUNS = 4000;

    private const string WHITESPACE = " \t\n\r\f\0";

    private const string DELIMITERS = '()<>[]{}/%';

    /** @var list<float> */
    private array $ctm = [1, 0, 0, 1, 0, 0];

    /** @var list<list<float>> */
    private array $ctmStack = [];

    /** @var list<float> */
    private array $tm = [1, 0, 0, 1, 0, 0];

    /** @var list<float> */
    private array $tlm = [1, 0, 0, 1, 0, 0];

    private float $leading = 0.0;

    private string $font = '';

    private float $size = 0.0;

    /** @var list<TextRun> */
    private array $runs = [];

    /** @param  array<string, true>  $undecodableFonts  font resource names whose bytes are not single-byte codes */
    private function __construct(private readonly int $page, private readonly array $undecodableFonts) {}

    /**
     * @param  array<string, true>  $undecodableFonts
     * @return list<TextRun>
     */
    public static function extract(string $content, int $page, array $undecodableFonts = []): array
    {
        $extractor = new self($page, $undecodableFonts);
        $extractor->interpret($content);

        return $extractor->runs;
    }

    private function interpret(string $content): void
    {
        $operands = [];
        $arrays = [];
        foreach ($this->tokens($content) as [$kind, $value]) {
            if (count($this->runs) >= self::MAX_RUNS) {
                return;
            }
            if ($kind === '[') {
                $arrays[] = [];
            } elseif ($kind === ']') {
                $items = array_pop($arrays) ?? [];
                $arrays === [] ? $operands[] = ['arr', $items] : $arrays[count($arrays) - 1][] = ['arr', $items];
            } elseif ($kind === 'op') {
                $this->operator((string) $value, $operands);
                $operands = [];
                $arrays = [];
            } else {
                $arrays === [] ? $operands[] = [$kind, $value] : $arrays[count($arrays) - 1][] = [$kind, $value];
            }
        }
    }

    /** @param  list<array{0: string, 1: mixed}>  $operands */
    private function operator(string $operator, array $operands): void
    {
        $n = array_map(static fn (array $o): float => $o[0] === 'num' ? (float) $o[1] : 0.0, $operands);
        match ($operator) {
            'q' => $this->ctmStack[] = $this->ctm,
            'Q' => $this->ctm = array_pop($this->ctmStack) ?? [1, 0, 0, 1, 0, 0],
            'cm' => count($n) === 6 ? $this->ctm = $this->multiply($n, $this->ctm) : null,
            'BT' => $this->tm = $this->tlm = [1, 0, 0, 1, 0, 0],
            'Tf' => $this->setFont($operands),
            'TL' => $this->leading = $n[0] ?? 0.0,
            'Td' => $this->moveLine($n[0] ?? 0.0, $n[1] ?? 0.0),
            'TD' => $this->moveLine($n[0] ?? 0.0, $n[1] ?? 0.0, true),
            'Tm' => count($n) === 6 ? $this->tm = $this->tlm = $n : null,
            'T*' => $this->moveLine(0.0, -$this->leading),
            'Tj' => $this->show($this->strings($operands)),
            "'" => $this->nextLineShow($this->strings($operands)),
            '"' => $this->nextLineShow($this->strings(array_slice($operands, 2))),
            'TJ' => $this->show($this->strings($operands)),
            default => null,
        };
    }

    /** @param  list<array{0: string, 1: mixed}>  $operands */
    private function setFont(array $operands): void
    {
        foreach ($operands as [$kind, $value]) {
            if ($kind === 'name') {
                $this->font = (string) $value;
            } elseif ($kind === 'num') {
                $this->size = (float) $value;
            }
        }
    }

    private function moveLine(float $tx, float $ty, bool $setLeading = false): void
    {
        if ($setLeading) {
            $this->leading = -$ty;
        }
        $this->tlm = $this->multiply([1, 0, 0, 1, $tx, $ty], $this->tlm);
        $this->tm = $this->tlm;
    }

    private function nextLineShow(string $bytes): void
    {
        $this->moveLine(0.0, -$this->leading);
        $this->show($bytes);
    }

    private function show(string $bytes): void
    {
        $text = isset($this->undecodableFonts[$this->font]) ? '' : $this->decode($bytes);
        $chars = mb_strlen($text);
        $m = $this->multiply($this->tm, $this->ctm);
        // Undecodable (two-byte) text still advances the pen by an estimate.
        $advance = 0.5 * $this->size * ($text === '' ? strlen($bytes) / 2 : $chars);
        $this->tm = $this->multiply([1, 0, 0, 1, $advance, 0], $this->tm);

        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text)));
        if ($clean === '' || preg_match('/[\p{L}\p{N}]/u', $clean) !== 1) {
            return;
        }

        $size = $this->size * hypot($m[2], $m[3]);
        $width = $advance * hypot($m[0], $m[1]);
        $this->append(new TextRun($this->page, round($m[4], 2), round($m[5], 2), round($width, 2), round($size, 2), $clean));
    }

    private function append(TextRun $run): void
    {
        $last = $this->runs === [] ? null : $this->runs[count($this->runs) - 1];
        $gap = $last === null ? INF : $run->x - $last->right();
        if ($last !== null && abs($last->y - $run->y) < 0.5 && $gap >= -1 && $gap <= max($run->size, 1.0) * 1.5) {
            $this->runs[count($this->runs) - 1] = new TextRun($last->page, $last->x, $last->y, round($run->right() - $last->x, 2), $last->size,
                $last->text.($gap > $run->size * 0.2 ? ' ' : '').$run->text);

            return;
        }
        $this->runs[] = $run;
    }

    /** @param  list<array{0: string, 1: mixed}>  $operands */
    private function strings(array $operands): string
    {
        $out = '';
        foreach ($operands as [$kind, $value]) {
            if ($kind === 'str') {
                $out .= (string) $value;
            } elseif ($kind === 'arr' && is_array($value)) {
                foreach ($value as [$itemKind, $item]) {
                    $out .= match (true) {
                        $itemKind === 'str' => (string) $item,
                        $itemKind === 'num' && (float) $item <= -200 => ' ',
                        default => '',
                    };
                }
            }
        }

        return $out;
    }

    private function decode(string $bytes): string
    {
        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return list<float>
     */
    private function multiply(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    /**
     * Content stream tokens: [kind, value] with kind num|str|name|op|[|].
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private function tokens(string $s): \Generator
    {
        $length = strlen($s);
        $i = 0;
        while ($i < $length) {
            $c = $s[$i];
            if (str_contains(self::WHITESPACE, $c)) {
                $i++;
            } elseif ($c === '%') {
                $i = $this->skipLine($s, $i);
            } elseif ($c === '(') {
                [$value, $i] = $this->literal($s, $i + 1);
                yield ['str', $value];
            } elseif ($c === '<' && ($s[$i + 1] ?? '') === '<') {
                $i = $this->skipDictionary($s, $i);
            } elseif ($c === '<') {
                $end = strpos($s, '>', $i) ?: $length;
                $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $end - $i - 1));
                yield ['str', (string) hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex.'0')];
                $i = $end + 1;
            } elseif ($c === '[' || $c === ']') {
                yield [$c, null];
                $i++;
            } elseif ($c === '/') {
                $end = $this->regularEnd($s, $i + 1);
                yield ['name', substr($s, $i + 1, $end - $i - 1)];
                $i = $end;
            } elseif (str_contains(self::DELIMITERS, $c)) {
                $i++; // stray ')', '>', '{', '}'
            } else {
                $end = $this->regularEnd($s, $i);
                $word = substr($s, $i, $end - $i);
                $i = $end;
                if (is_numeric($word)) {
                    yield ['num', (float) $word];
                } elseif ($word === 'BI') {
                    $i = $this->skipInlineImage($s, $i);
                } else {
                    yield ['op', $word];
                }
            }
        }
    }

    /** @return array{0: string, 1: int} */
    private function literal(string $s, int $i): array
    {
        $out = '';
        $depth = 1;
        $length = strlen($s);
        $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
        while ($i < $length) {
            $c = $s[$i++];
            if ($c === '\\') {
                $next = $s[$i] ?? '';
                if (preg_match('/[0-7]{1,3}/A', $s, $m, 0, $i) === 1) {
                    $out .= chr((int) octdec($m[0]) & 0xFF);
                    $i += strlen($m[0]);
                } else {
                    $out .= $escapes[$next] ?? '';
                    $i++;
                }
            } elseif ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')' && --$depth === 0) {
                break;
            } else {
                $out .= $c;
            }
        }

        return [$out, $i];
    }

    private function regularEnd(string $s, int $i): int
    {
        $length = strlen($s);
        while ($i < $length && ! str_contains(self::WHITESPACE.self::DELIMITERS, $s[$i])) {
            $i++;
        }

        return $i;
    }

    private function skipLine(string $s, int $i): int
    {
        $end = strcspn($s, "\r\n", $i);

        return $i + $end;
    }

    private function skipDictionary(string $s, int $i): int
    {
        $depth = 0;
        $length = strlen($s);
        while ($i < $length) {
            if (substr($s, $i, 2) === '<<') {
                $depth++;
                $i += 2;
            } elseif (substr($s, $i, 2) === '>>') {
                $i += 2;
                if (--$depth === 0) {
                    return $i;
                }
            } elseif ($s[$i] === '(') {
                [, $i] = $this->literal($s, $i + 1);
            } else {
                $i++;
            }
        }

        return $i;
    }

    private function skipInlineImage(string $s, int $i): int
    {
        if (preg_match('/\sEI(?=[\s]|$)/', $s, $m, PREG_OFFSET_CAPTURE, $i) === 1) {
            return (int) $m[0][1] + 3;
        }

        return strlen($s);
    }
}
