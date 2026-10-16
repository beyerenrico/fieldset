<?php

declare(strict_types=1);

use App\Domain\Forms\Import\Mapping\FieldMapper;
use App\Domain\Forms\Import\Mapping\MappedField;
use App\Domain\Forms\Import\Mapping\ScriptPatterns;
use App\Domain\Forms\Import\Mapping\UnmappedField;
use App\Domain\Forms\Import\Model\AcroField;

it('recognizes Acrobat format scripts', function (?string $script, ?array $expected): void {
    expect(ScriptPatterns::format($script))->toBe($expected);
})->with([
    'none' => [null, null],
    'blank' => ['  ', null],
    'money' => ['AFNumber_Format(2, 0, 0, 0, "€", false);', ['kind' => 'number', 'decimals' => 2, 'currency' => '€']],
    'number keystroke' => ["AFNumber_Keystroke(0, 1, 0, 0, '', true)", ['kind' => 'number', 'decimals' => 0, 'currency' => '']],
    'percent' => ['AFPercent_Format(1, 0);', ['kind' => 'percent', 'decimals' => 1]],
    'date ex' => ['AFDate_FormatEx("dd.mm.yyyy");', ['kind' => 'date', 'pattern' => 'dd.mm.yyyy']],
    'datetime ex' => ['AFDate_FormatEx("dd.mm.yyyy HH:MM");', ['kind' => 'datetime', 'pattern' => 'dd.mm.yyyy HH:MM']],
    'time ex' => ['AFTime_FormatEx("HH:MM")', ['kind' => 'time', 'pattern' => 'HH:MM']],
    'date table' => ['AFDate_Format(2);', ['kind' => 'date']],
    'date table with time' => ['AFDate_Format(13);', ['kind' => 'datetime']],
    'time table' => ['AFTime_Format(0);', ['kind' => 'time']],
    'phone' => ['AFSpecial_Format(2);', ['kind' => 'phone']],
    'zip' => ['AFSpecial_Format(0);', ['kind' => 'special']],
    'mask' => ['AFSpecial_KeystrokeEx("99999");', ['kind' => 'special']],
    'custom' => ['event.value = event.value.toUpperCase();', ['kind' => 'custom']],
    'two calls' => ['AFNumber_Format(2, 0, 0, 0, "", false); app.alert(1);', ['kind' => 'custom']],
]);

it('recognizes AFSimple_Calculate in all list notations and nothing else', function (string $script, ?array $expected): void {
    expect(ScriptPatterns::calculation($script))->toBe($expected);
})->with([
    'new Array' => ['AFSimple_Calculate("SUM", new Array ("a", "b.0"));', ['function' => 'SUM', 'fields' => ['a', 'b.0']]],
    'literal array' => ["AFSimple_Calculate('PRD', ['qty', 'price'])", ['function' => 'PRD', 'fields' => ['qty', 'price']]],
    'comma string' => ['AFSimple_Calculate("AVG", "x, y ,z");', ['function' => 'AVG', 'fields' => ['x', 'y', 'z']]],
    'with comment' => ["/* generated */\nAFSimple_Calculate(\"MAX\", new Array(\"a\"));", ['function' => 'MAX', 'fields' => ['a']]],
    'event.value prefix' => ['event.value = AFSimple_Calculate("MIN", ["a","b"]);', ['function' => 'MIN', 'fields' => ['a', 'b']]],
    'unknown function' => ['AFSimple_Calculate("MEDIAN", ["a"]);', null],
    'custom' => ['event.value = this.getField("a").value * 0.1;', null],
    'simplified field notation' => ['/** BVCALC a * b EVCALC **/ event.value = AFMakeNumber(getField("a").value) * AFMakeNumber(getField("b").value)', null],
    'injected code' => ['AFSimple_Calculate("SUM", new Array ("a", app.alert(1)));', null],
]);

it('maps currency strings to ISO codes', function (string $symbol, ?string $code): void {
    expect(ScriptPatterns::currency($symbol))->toBe($code);
})->with([['', null], ['€', 'EUR'], [' $ ', 'USD'], ['£', 'GBP'], ['CHF', 'CHF'], ['SEK', 'SEK'], ['Kč', '?']]);

it('maps fields per the §8.13 table and reports what it cannot import', function (AcroField $field, string $expected): void {
    $result = (new FieldMapper)->map($field);

    expect($result instanceof MappedField ? $result->type : 'unmapped:'.$result->reason)->toBe($expected);
})->with([
    'text' => [new AcroField('a', 'Tx', 0, 4, 1, [0, 0, 1, 1]), 'text'],
    'password' => [new AcroField('a', 'Tx', AcroField::FF_PASSWORD, 4, 1, [0, 0, 1, 1]), 'text'],
    'file select' => [new AcroField('a', 'Tx', AcroField::FF_FILE_SELECT, 4, 1, [0, 0, 1, 1]), 'unmapped:file_select'],
    'hidden (NoView)' => [new AcroField('a', 'Tx', 0, 32, 1, [0, 0, 1, 1]), 'unmapped:hidden'],
    'push button' => [new AcroField('a', 'Btn', AcroField::FF_PUSHBUTTON, 4, 1, [0, 0, 1, 1]), 'unmapped:push_button'],
    'radio without options' => [new AcroField('a', 'Btn', AcroField::FF_RADIO, 4, 1, [0, 0, 1, 1]), 'unmapped:no_options'],
    'check box group' => [new AcroField('a', 'Btn', 0, 4, 1, [0, 0, 1, 1], options: [['value' => 'A', 'label' => 'A'], ['value' => 'B', 'label' => 'B']]), 'radio'],
    'list box' => [new AcroField('a', 'Ch', 0, 4, 1, [0, 0, 1, 1], options: [['value' => 'x', 'label' => 'X']]), 'select'],
    'combo with multi-select flag' => [new AcroField('a', 'Ch', AcroField::FF_COMBO | AcroField::FF_MULTI_SELECT, 4, 1, [0, 0, 1, 1], options: [['value' => 'x', 'label' => 'X']]), 'select'],
    'choice without options' => [new AcroField('a', 'Ch', 0, 4, 1, [0, 0, 1, 1]), 'unmapped:no_options'],
    'percent' => [new AcroField('a', 'Tx', 0, 4, 1, [0, 0, 1, 1], formatScript: 'AFPercent_Format(0, 0);'), 'number'],
    'keystroke only' => [new AcroField('a', 'Tx', 0, 4, 1, [0, 0, 1, 1], keystrokeScript: 'AFDate_KeystrokeEx("yyyy-mm-dd");'), 'date'],
    'custom calculation' => [new AcroField('a', 'Tx', 0, 4, 1, [0, 0, 1, 1], calculateScript: 'event.value = 1;'), 'unmapped:custom_calculation'],
    'unreadable calculation stream' => [new AcroField('a', 'Tx', 0, 4, 1, [0, 0, 1, 1], calculateScript: ''), 'unmapped:custom_calculation'],
    'unknown type' => [new AcroField('a', 'Xx', 0, 4, 1, [0, 0, 1, 1]), 'unmapped:unsupported_type'],
]);

it('guesses the default currency for unknown symbols and warns', function (): void {
    $result = (new FieldMapper('CHF'))->map(new AcroField('a', 'Tx', 0, 4, 1, [0, 0, 1, 1], formatScript: 'AFNumber_Format(3, 0, 0, 0, "Kč", false);'));

    expect($result)->toBeInstanceOf(MappedField::class)
        ->and($result->properties)->toBe(['currency' => 'CHF', 'scale' => 3])
        ->and($result->confidence)->toBe(0.8)
        ->and($result->warnings[0]['code'])->toBe('currency_guessed');
});

it('keeps option values within spec limits and unique', function (): void {
    $long = str_repeat('x', 150);
    $result = (new FieldMapper)->map(new AcroField('a', 'Ch', AcroField::FF_COMBO, 4, 1, [0, 0, 1, 1], options: [
        ['value' => $long, 'label' => ''], ['value' => 'dup', 'label' => 'One'], ['value' => 'dup', 'label' => 'Two'], ['value' => ' ', 'label' => 'blank'],
    ]));

    expect($result)->toBeInstanceOf(MappedField::class)
        ->and($result->properties['options'])->toBe([
            ['value' => str_repeat('x', 100), 'label' => str_repeat('x', 100)],
            ['value' => 'dup', 'label' => 'One'],
        ]);
});

it('does not mark calculated fields read-only or required', function (): void {
    $result = (new FieldMapper)->map(new AcroField('t', 'Tx', AcroField::FF_READ_ONLY | AcroField::FF_REQUIRED, 4, 1, [0, 0, 1, 1],
        calculateScript: 'AFSimple_Calculate("SUM", ["a"]);'));

    expect($result)->not->toBeInstanceOf(UnmappedField::class)
        ->and($result->properties)->toBe(['format' => 'number']);
});
