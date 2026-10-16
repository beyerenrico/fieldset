<?php

declare(strict_types=1);

use FormSchema\Support\EcmaRegex;
use FormSchema\Support\Pattern\CodePointSet;
use FormSchema\Support\Pattern\PatternParser;

require_once __DIR__.'/../Support/helpers.php';

// A JavaScript `\u` escape prefix (backslash + u), kept out of literal source text.
$u = '\\'.'u';

test('isValid accepts the §3.4 subset', function (string $pattern): void {
    expect(EcmaRegex::isValid($pattern))->toBeTrue()
        ->and(EcmaRegex::toPcre($pattern))->toBeString();
})->with([
    'empty pattern' => [''],
    'literal' => ['abc'],
    'anchored class and quantifier' => ['^[A-Z]{2}\d+$'],
    'quantified group without inner quantifier' => ['(?:ab)+'],
    'capturing group' => ['(ab)+'],
    'optional group with inner quantifier' => ['(\d{3}-)?\d{4}'],
    'optional alternation' => ['(a|b)?'],
    'zero-or-one braces on a complex group' => ['(a+){0,1}'],
    'lazy zero-or-one on a complex group' => ['(a|b)??'],
    'lookahead' => ['a(?=b)'],
    'negative lookahead' => ['a(?!b)'],
    'alternation' => ['a|b|'],
    'empty group' => ['()'],
    'unicode escape' => [$u.'00e4'],
    'unicode code point escape' => [$u.'{1F600}'],
    'unicode code point with leading zeros' => [$u.'{0000041}'],
    'hex escape' => ['\x41'],
    'nul escape' => ['\0'],
    'control escapes' => ['\t\n\r\f\v'],
    'escaped syntax characters' => ['\(\)\[\]\{\}\.\*\+\?\^\$\|\/\-\\\\'],
    'empty class' => ['[]'],
    'negated empty class' => ['[^]'],
    'slash' => ['a/b'],
    'class with escapes and ranges' => ['[\d\sA-F_-]'],
    'class with leading dash' => ['[-a]'],
    'dash after a range' => ['[a-c-e]'],
    'dash range' => ['[--a]'],
    'syntax characters inside a class' => ['[(){}*+?.|$^\\[\\]]'],
    'class escape ranges' => ['[\x41-\x5A'.$u.'0061-'.$u.'007A]'],
    'word boundaries' => ['\bfoo\B'],
    'braces' => ['a{3}b{2,}c{0,1000}'],
    'leading zeros in bounds' => ['a{0002}'],
    'lazy quantifiers' => ['a*?b+?c??d{2}?'],
    'lone low surrogate escape' => [$u.'DC00'],
    'high surrogate without partner' => [$u.'D83Dx'],
]);

test('isValid rejects syntax outside the subset', function (string $pattern): void {
    expect(EcmaRegex::isValid($pattern))->toBeFalse()
        ->and(EcmaRegex::toPcre($pattern))->toBeNull();
})->with([
    'unclosed group' => ['(abc'],
    'unbalanced closing parenthesis' => ['abc)'],
    'unclosed class' => ['[abc'],
    'nothing to repeat' => ['*a'],
    'quantified assertion' => ['^*'],
    'quantified lookahead' => ['(?=a)+'],
    'quantified boundary' => ['\b+'],
    'double quantifier' => ['a**'],
    'possessive' => ['a++'],
    'quantified braces twice' => ['a{2}{3}'],
    'lone brace' => ['a{'],
    'lone closing brace' => ['a}'],
    'lone closing bracket' => [']'],
    'empty lower bound' => ['a{,3}'],
    'bound above 1000' => ['a{1001}'],
    'huge bound' => ['a{99999}'],
    'range above 1000' => ['a{2,1001}'],
    'reversed range' => ['a{3,1}'],
    'unterminated braces' => ['a{2,3'],
    'nested quantifier' => ['(a+)+'],
    'quantified alternation' => ['(a|b)*'],
    'quantified non-capturing alternation' => ['(?:a|b)+'],
    'deeply nested quantifier' => ['((a?)b){2}'],
    'quantifier inside a lookahead inside a repeated group' => ['((?=a+)b)+'],
    'inline flags' => ['(?i)abc'],
    'comment group' => ['(?#c)a'],
    'atomic group' => ['(?>a)b'],
    'named group' => ['(?<year>\d{4})'],
    'python named group' => ['(?P<n>a)'],
    'branch reset' => ['(?|a|b)'],
    'subroutine' => ['(a)(?1)'],
    'verb' => ['(*UTF)a'],
    'lookbehind' => ['(?<=a)b'],
    'negative lookbehind' => ['(?<!a)b'],
    'numbered backreference' => ['(a)\1'],
    'named backreference' => ['\k<n>'],
    'property escape' => ['\p{L}'],
    'control escape' => ['\cA'],
    'identity escape of a letter' => ['\a'],
    'identity escape of other characters' => ['\@'],
    'multibyte identity escape' => ['\ä'],
    'octal escape' => ['\012'],
    'nul followed by a digit' => ['\01'],
    'short hex escape' => ['\x4'],
    'non-hex hex escape' => ['\xZZ'],
    'short unicode escape' => [$u.'12'],
    'empty code point escape' => [$u.'{}'],
    'code point above 10FFFF' => [$u.'{110000}'],
    'too many code point digits' => [$u.'{0010FFFF0}'],
    'non-hex code point' => [$u.'{12G}'],
    'unterminated code point escape' => [$u.'{12'],
    'boundary inside a class' => ['[\b]'],
    'digit escape inside a class' => ['[\1]'],
    'class escape as range start' => ['[\d-z]'],
    'class escape as range end' => ['[a-\d]'],
    'class range out of order' => ['[z-a]'],
    'POSIX class' => ['[[:alpha:]]'],
    'unescaped bracket inside a class' => ['[a[]'],
    'trailing backslash' => ['abc\\'],
    'invalid UTF-8' => ["\xff"],
]);

test('toPcre spells every class out as code point ranges', function () use ($u): void {
    expect(EcmaRegex::toPcre('abc'))->toBe('~(*UTF)[\x{61}][\x{62}][\x{63}]~')
        ->and(EcmaRegex::toPcre('^a$'))->toBe('~(*UTF)^[\x{61}]\z~')
        ->and(EcmaRegex::toPcre('[]'))->toBe('~(*UTF)(?:(?!))~')
        ->and(EcmaRegex::toPcre('[^]'))->toBe('~(*UTF)[\x{0}-\x{D7FF}\x{E000}-\x{10FFFF}]~')
        ->and(EcmaRegex::toPcre('a?b*c+'))->toBe('~(*UTF)[\x{61}]{0,1}[\x{62}]{0,}[\x{63}]{1,}~')
        ->and(EcmaRegex::toPcre($u.'D83D'.$u.'DE00'))->toBe('~(*UTF)[\x{1F600}]~')
        ->and(EcmaRegex::toPcre($u.'DC00'))->toBe('~(*UTF)(?:(?!))~')
        ->and(EcmaRegex::toPcre('(?:a)(b)'))->toBe('~(*UTF)(?:[\x{61}])([\x{62}])~');
});

test('PatternParser translates or rejects', function (): void {
    expect(PatternParser::translate('a|b'))->toBe('[\x{61}]|[\x{62}]')
        ->and(PatternParser::translate('(a'))->toBeNull();
});

test('matches follows ECMAScript u-mode semantics', function (string $pattern, string $subject, bool $expected): void {
    expect(EcmaRegex::matches($pattern, $subject))->toBe($expected);
})->with([
    'unanchored' => ['b', 'abc', true],
    'anchored miss' => ['^b', 'abc', false],
    'slash literal' => ['a/b', 'xa/by', true],
    'escaped slash' => ['^\/$', '/', true],
    'escaped dash' => ['^\-$', '-', true],
    'case-sensitive' => ['^abc$', 'ABC', false],
    'unicode escape' => ['^'.$u.'00e4$', 'ä', true],
    'unicode escape miss' => ['^'.$u.'00e4$', 'a', false],
    'surrogate pair escape is one code point' => ['^'.$u.'D83D'.$u.'DE00$', '😀', true],
    'astral range' => ['^['.$u.'{1F600}-'.$u.'{1F64F}]$', '😃', true],
    'lone surrogate never matches' => [$u.'DC00', "\u{FFFD}", false],
    'empty class never matches' => ['[]', 'anything', false],
    'negated empty class matches newline' => ['^[^]$', "\n", true],
    'dot matches one astral code point' => ['^.$', '😀', true],
    'dot does not match LF' => ['.', "\n", false],
    'dot does not match CR' => ['.', "\r", false],
    'dot does not match U+2028' => ['.', "\u{2028}", false],
    'dot in a class is literal' => ['^[.]$', '.', true],
    'dot in a class is not a wildcard' => ['[.]', 'x', false],
    '\d is ASCII only' => ['^\d$', "\u{0663}", false],
    '\D matches Arabic-Indic digits' => ['^\D$', "\u{0663}", true],
    '\w is ASCII only' => ['^\w$', 'é', false],
    '\W matches é' => ['^\W$', 'é', true],
    '\s matches NBSP' => ['^\s$', "\u{00A0}", true],
    '\s matches U+3000' => ['^\s$', "\u{3000}", true],
    '\s excludes U+0085' => ['^\s$', "\u{0085}", false],
    '\s excludes U+180E' => ['^\s$', "\u{180E}", false],
    '\s in a class matches FEFF' => ['^[x\s]$', "\u{FEFF}", true],
    '\S rejects NBSP' => ['^\S$', "\u{00A0}", false],
    '\S in a negated class' => ['^[^\S]$', ' ', true],
    '\S in a class with a space' => ['^[ \S]$', ' ', true],
    '\v is vertical tab' => ['^\v$', "\x0B", true],
    '\0 is NUL' => ['^\0$', "\x00", true],
    '\b ASCII boundary before é' => ['a\b', 'aé', true],
    '\b boundary miss' => ['\bfoo\b', 'afoob', false],
    '\B inside a word' => ['o\Bo', 'foo', true],
    '\x hex escape' => ['^\x41$', 'A', true],
    'class range from escapes' => ['^[\x41-\x43]+$', 'ABC', true],
    'dash literal after a range' => ['^[a-c-e]$', '-', true],
    'dollar does not match before a trailing newline' => ['^abc$', "abc\n", false],
    'lazy quantifier still matches' => ['^a+?$', 'aaa', true],
    'lookahead' => ['^a(?=b)', 'ab', true],
    'negative lookahead' => ['^a(?!b)', 'ab', false],
    'invalid pattern never rejects' => ['(', 'anything', true],
    'unsupported pattern never rejects' => ['(?<=a)b', 'zzz', true],
]);

test('matches fails closed for long subjects and engine errors', function (): void {
    expect(EcmaRegex::matches('a', str_repeat('a', 4096)))->toBeTrue()
        ->and(EcmaRegex::matches('a', str_repeat('a', 4097)))->toBeFalse()
        ->and(EcmaRegex::matches('a', "a\xff"))->toBeFalse();
});

test('CodePointSet merges, complements and renders ranges', function (): void {
    $set = CodePointSet::of([[0x63, 0x65], [0x61, 0x62], [0x70, 0x70]]);

    expect($set->ranges)->toBe([[0x61, 0x65], [0x70, 0x70]])
        ->and($set->union(CodePointSet::single(0x66))->ranges)->toBe([[0x61, 0x66], [0x70, 0x70]])
        ->and(CodePointSet::of([[0, CodePointSet::MAX]])->complement()->ranges)->toBe([])
        ->and(CodePointSet::of([])->complement()->ranges)->toBe([[0, CodePointSet::MAX]])
        ->and(CodePointSet::of([[0xD800, 0xDFFF]])->toPcre())->toBe('(?:(?!))')
        ->and(CodePointSet::of([[0xD000, 0xE000]])->toPcre())->toBe('[\x{D000}-\x{D7FF}\x{E000}]')
        ->and(CodePointSet::dot()->ranges)->toBe([[0, 9], [11, 12], [14, 0x2027], [0x202A, CodePointSet::MAX]]);
});
