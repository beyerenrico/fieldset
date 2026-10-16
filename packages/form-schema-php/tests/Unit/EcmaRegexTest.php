<?php

declare(strict_types=1);

use FormSchema\Support\EcmaRegex;

require_once __DIR__.'/../Support/helpers.php';

// A JavaScript `\u` escape prefix (backslash + u), kept out of literal source text.
$u = '\\'.'u';

test('isValid accepts ECMAScript patterns PCRE can run', function (string $pattern): void {
    expect(EcmaRegex::isValid($pattern))->toBeTrue()
        ->and(EcmaRegex::toPcre($pattern))->toBeString();
})->with([
    'literal' => ['abc'],
    'anchored class and quantifier' => ['^[A-Z]{2}\d+$'],
    'non-capturing group' => ['(?:a|b)+'],
    'capturing group' => ['(ab)+'],
    'lookahead' => ['a(?=b)'],
    'negative lookahead' => ['a(?!b)'],
    'named group' => ['(?<year>\d{4})'],
    'unicode escape' => [$u.'00e4'],
    'empty class' => ['[]'],
    'negated empty class' => ['[^]'],
    'slash' => ['a/b'],
    'escaped slash' => ['a\/b'],
    'slash in class' => ['[/]'],
    'lookbehind syntax inside a class' => ['[(?<=]'],
    'digit escape inside a class' => ['[\1]'],
    'identity escape' => ['\a'],
    'multibyte escape' => ['\ä'],
    'empty pattern' => [''],
]);

test('isValid rejects invalid or unsupported patterns', function (string $pattern): void {
    expect(EcmaRegex::isValid($pattern))->toBeFalse()
        ->and(EcmaRegex::toPcre($pattern))->toBeNull();
})->with([
    'unclosed group' => ['(abc'],
    'unclosed class' => ['[abc'],
    'nothing to repeat' => ['*a'],
    'quantifier out of order' => ['a{3,1}'],
    'lookbehind' => ['(?<=a)b'],
    'negative lookbehind' => ['(?<!a)b'],
    'numbered backreference' => ['(a)\1'],
    'named backreference' => ['(?<n>a)\k<n>'],
    'trailing backslash' => ['abc\\'],
    'invalid UTF-8' => ["\xff"],
]);

test('toPcre adds delimiters and modifiers', function (): void {
    expect(EcmaRegex::toPcre('abc'))->toBe('/abc/uD')
        ->and(EcmaRegex::toPcre('a/b'))->toBe('/a\/b/uD')
        ->and(EcmaRegex::toPcre('\\'.'u00E4'))->toBe('/\x{00E4}/uD')
        ->and(EcmaRegex::toPcre('[]'))->toBe('/(?!)/uD')
        ->and(EcmaRegex::toPcre('[^]'))->toBe('/[\s\S]/uD');
});

test('matches follows ECMAScript semantics', function (string $pattern, string $subject, bool $expected): void {
    expect(EcmaRegex::matches($pattern, $subject))->toBe($expected);
})->with([
    'unanchored' => ['b', 'abc', true],
    'anchored miss' => ['^b', 'abc', false],
    'slash literal' => ['a/b', 'xa/by', true],
    'escaped slash' => ['^\/$', '/', true],
    'slash in class' => ['^[/]$', '/', true],
    'unicode escape' => ['^'.$u.'00e4$', 'ä', true],
    'unicode escape miss' => ['^'.$u.'00e4$', 'a', false],
    'short \u is an identity escape' => ['^\u12$', 'u12', true],
    'empty class never matches' => ['[]', 'anything', false],
    'empty class never matches empty' => ['[]', '', false],
    'negated empty class matches newline' => ['^[^]$', "\n", true],
    'dot matches a character' => ['^.$', 'a', true],
    'dot matches a multibyte character' => ['^.$', 'ä', true],
    'dot does not match LF' => ['.', "\n", false],
    'dot does not match CR' => ['.', "\r", false],
    'dot does not match U+2028' => ['.', "\u{2028}", false],
    'dot in a class is literal' => ['^[.]$', '.', true],
    'dot in a class is not a wildcard' => ['[.]', 'x', false],
    '\s matches NBSP' => ['^\s$', "\u{00A0}", true],
    '\s matches U+3000' => ['^\s$', "\u{3000}", true],
    '\s in a class matches NBSP' => ['^[\s]$', "\u{00A0}", true],
    '\s in a class matches FEFF' => ['^[x\s]$', "\u{FEFF}", true],
    '\S rejects NBSP' => ['^\S$', "\u{00A0}", false],
    '\S accepts a letter' => ['^\S$', 'x', true],
    '\S in a class' => ['^[\S]$', 'x', true],
    '\v is vertical tab' => ['^\v$', "\x0B", true],
    '\v is not v' => ['^\v$', 'v', false],
    '\0 is NUL' => ['^\0$', "\x00", true],
    '\a is identity' => ['^\a$', 'a', true],
    '\e is identity' => ['^\e$', 'e', true],
    '\d digit' => ['^\d$', '5', true],
    '\w word' => ['^\w+$', 'a_1', true],
    '\b boundary' => ['\bfoo\b', 'a foo b', true],
    '\b boundary miss' => ['\bfoo\b', 'afoob', false],
    '\x hex escape' => ['^\x41$', 'A', true],
    'escaped dot' => ['^\.$', '.', true],
    'escaped dot is literal' => ['^\.$', 'x', false],
    'multibyte escape' => ['^\ä$', 'ä', true],
    'dollar does not match before a trailing newline' => ['^abc$', "abc\n", false],
    'dollar at end' => ['^abc$', 'abc', true],
    'lookahead' => ['^a(?=b)', 'ab', true],
    'invalid pattern never rejects' => ['(', 'anything', true],
    'unsupported pattern never rejects' => ['(?<=a)b', 'zzz', true],
]);
