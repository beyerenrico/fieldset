<?php

declare(strict_types=1);

use Fieldset\Lint\NumberingFormat;

require_once __DIR__.'/../Support/helpers.php';

test('validates numbering formats and reset rules', function (string $format, string $reset, bool $valid): void {
    expect(NumberingFormat::isValid($format, $reset))->toBe($valid);
})->with([
    'spec example' => ['SR-{YYYY}-{SEQ:5}', 'never', true],
    'sequence only' => ['{SEQ:1}', 'never', true],
    'widest sequence' => ['{SEQ:12}', 'never', true],
    'all literal characters' => ['A.b_c/d-9{SEQ:3}', 'never', true],
    'sequence width 0' => ['{SEQ:0}', 'never', false],
    'sequence width 13' => ['{SEQ:13}', 'never', false],
    'zero-padded width' => ['{SEQ:05}', 'never', false],
    'sequence twice' => ['{SEQ:2}-{SEQ:3}', 'never', false],
    'missing sequence' => ['SR-{YYYY}', 'never', false],
    'empty format' => ['', 'never', false],
    'space is not a literal' => ['SR {SEQ:5}', 'never', false],
    'hash is not a literal' => ['SR#{SEQ:5}', 'never', false],
    'umlaut is not a literal' => ['Ä-{SEQ:5}', 'never', false],
    'lowercase token' => ['{seq:5}', 'never', false],
    'unknown token' => ['{DD}-{SEQ:5}', 'never', false],
    'unclosed token' => ['{SEQ:5', 'never', false],
    'yearly with YYYY' => ['{YYYY}{SEQ:4}', 'yearly', true],
    'yearly with YY' => ['{YY}-{SEQ:4}', 'yearly', true],
    'yearly without year' => ['X-{MM}-{SEQ:4}', 'yearly', false],
    'monthly with YYYY and MM' => ['{YYYY}{MM}-{SEQ:4}', 'monthly', true],
    'monthly with YY and MM' => ['{YY}{MM}-{SEQ:4}', 'monthly', true],
    'monthly without MM' => ['{YYYY}-{SEQ:4}', 'monthly', false],
    'monthly without year' => ['{MM}-{SEQ:4}', 'monthly', false],
    'never needs no year' => ['X-{SEQ:4}', 'never', true],
    'unknown reset behaves like never' => ['X-{SEQ:4}', 'weekly', true],
]);

test('reset defaults to never', function (): void {
    expect(NumberingFormat::isValid('N-{SEQ:6}'))->toBeTrue()
        ->and(NumberingFormat::isValid('N-{SEQ:6}', 'yearly'))->toBeFalse();
});
