<?php

declare(strict_types=1);

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Evaluation\SignatureLocation;
use FormSchema\Evaluation\TypeChecker;
use FormSchema\Evaluation\Violation;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Type checks of spec §5.6 (codes `type_mismatch` … `invalid_location`).
 */

$element = static fn (string $type, array $properties = []): Element => Definition::fromJson(fs_definition([fs_element($type, 'field', ['label' => 'Field', ...$properties])]))->elements[0];

$options = ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B'], ['value' => 'c', 'label' => 'C']]];
$uuid = '0b6e4d3c-8f1a-4c2b-9d7e-1a2b3c4d5e6f';
$signature = ['attachment' => $uuid, 'signerName' => 'Willi', 'signedAt' => '2026-10-16T09:41:00+02:00'];
$location = ['lat' => 52.52, 'lng' => 13.405, 'accuracy' => 12.5, 'capturedAt' => '2026-10-16T09:41:00Z'];

test('type checks', function (string $type, array $properties, mixed $value, ?string $code) use ($element): void {
    $violation = TypeChecker::check($element($type, $properties), $value);

    expect($violation?->code)->toBe($code)
        ->and(TypeChecker::passes($element($type, $properties), $value))->toBe($code === null);
})->with([
    'null is never checked' => ['number', [], null, null],
    'text string' => ['text', [], 'hello', null],
    'text number' => ['text', [], 12, 'type_mismatch'],
    'textarea string' => ['textarea', [], "line\nline", null],
    'textarea bool' => ['textarea', [], true, 'type_mismatch'],
    'email valid' => ['email', [], 'a.b@example.co', null],
    'email unicode local part' => ['email', [], 'jürgen@beispiel.de', null],
    'email without dot in domain' => ['email', [], 'a@example', 'invalid_email'],
    'email with space' => ['email', [], 'a b@example.com', 'invalid_email'],
    'email with no-break space' => ['email', [], "a\u{00A0}b@example.com", 'invalid_email'],
    'email with two @' => ['email', [], 'a@b@example.com', 'invalid_email'],
    'email non-string' => ['email', [], ['a@b.de'], 'type_mismatch'],
    'phone valid' => ['phone', [], '+49 (030) 123/45.6-7', null],
    'phone three digits' => ['phone', [], '112', null],
    'phone too short' => ['phone', [], '12', 'invalid_phone'],
    'phone letters' => ['phone', [], '+49 abc', 'invalid_phone'],
    'phone plus not leading' => ['phone', [], '49+123', 'invalid_phone'],
    'phone too long' => ['phone', [], str_repeat('1', 33), 'invalid_phone'],
    'phone trailing newline' => ['phone', [], "112\n", 'invalid_phone'],
    'phone integer' => ['phone', [], 112, 'type_mismatch'],
    'number decimal string' => ['number', [], '-12.3456789', null],
    'number zero' => ['number', [], '0', null],
    'number JSON integer' => ['number', [], 12, 'decimal_string_required'],
    'number JSON float' => ['number', [], 1.5, 'decimal_string_required'],
    'number bool' => ['number', [], false, 'type_mismatch'],
    'number array' => ['number', [], ['1'], 'type_mismatch'],
    'number letters' => ['number', [], 'abc', 'invalid_number'],
    'number leading zero' => ['number', [], '01', 'invalid_number'],
    'number exponent' => ['number', [], '1e3', 'invalid_number'],
    'number plus sign' => ['number', [], '+1', 'invalid_number'],
    'number trailing dot' => ['number', [], '1.', 'invalid_number'],
    'number empty string' => ['number', [], '', 'invalid_number'],
    'money within scale' => ['money', ['currency' => 'EUR'], '12.5', null],
    'money JSON number' => ['money', ['currency' => 'EUR'], 12, 'decimal_string_required'],
    'money object' => ['money', ['currency' => 'EUR'], new stdClass, 'type_mismatch'],
    'money invalid string' => ['money', ['currency' => 'EUR'], '12,50', 'invalid_number'],
    'money scale exceeded' => ['money', ['currency' => 'EUR'], '1.234', 'scale_exceeded'],
    'money custom scale 4' => ['money', ['currency' => 'EUR', 'scale' => 4], '1.2345', null],
    'money custom scale 0' => ['money', ['currency' => 'EUR', 'scale' => 0], '1.5', 'scale_exceeded'],
    'money negative scale falls back to 2' => ['money', ['currency' => 'EUR', 'scale' => -1], '1.25', null],
    'money scale above 4 falls back to 2' => ['money', ['currency' => 'EUR', 'scale' => 5], '1.255', 'scale_exceeded'],
    'money fractional scale falls back to 2' => ['money', ['currency' => 'EUR', 'scale' => 1.5], '1.25', null],
    'date valid' => ['date', [], '2024-02-29', null],
    'date not a leap year' => ['date', [], '2026-02-29', 'invalid_date'],
    'date month 13' => ['date', [], '2026-13-01', 'invalid_date'],
    'date wrong format' => ['date', [], '16.10.2026', 'invalid_date'],
    'date with time' => ['date', [], '2026-10-16T09:41:00Z', 'invalid_date'],
    'date integer' => ['date', [], 20261016, 'type_mismatch'],
    'time HH:MM' => ['time', [], '00:00', null],
    'time HH:MM:SS' => ['time', [], '23:59:59', null],
    'time hour 24' => ['time', [], '24:00', 'invalid_time'],
    'time single digit hour' => ['time', [], '9:41', 'invalid_time'],
    'time seconds 60' => ['time', [], '12:00:60', 'invalid_time'],
    'time integer' => ['time', [], 941, 'type_mismatch'],
    'datetime with offset' => ['datetime', [], '2026-10-16T09:41:00+02:00', null],
    'datetime Z' => ['datetime', [], '2026-10-16T09:41:00Z', null],
    'datetime fraction' => ['datetime', [], '2026-10-16T09:41:00.123456789-05:30', null],
    'datetime without offset' => ['datetime', [], '2026-10-16T09:41:00', 'invalid_datetime'],
    'datetime without seconds' => ['datetime', [], '2026-10-16T09:41+02:00', 'invalid_datetime'],
    'datetime unreal date' => ['datetime', [], '2026-02-30T09:41:00Z', 'invalid_datetime'],
    'datetime bool' => ['datetime', [], true, 'type_mismatch'],
    'select option' => ['select', $options, 'b', null],
    'select unknown option' => ['select', $options, 'x', 'invalid_option'],
    'select option values are case sensitive' => ['select', $options, 'A', 'invalid_option'],
    'select object' => ['select', $options, fs_tree(['value' => 'a']), 'type_mismatch'],
    'select without options' => ['select', [], 'a', 'invalid_option'],
    'radio option' => ['radio', $options, 'a', null],
    'radio number' => ['radio', $options, 1, 'type_mismatch'],
    'multiselect options' => ['multiselect', $options, ['c', 'a'], null],
    'multiselect empty list' => ['multiselect', $options, [], null],
    'multiselect duplicate' => ['multiselect', $options, ['a', 'b', 'a'], 'invalid_option'],
    'multiselect unknown item' => ['multiselect', $options, ['a', 'x'], 'invalid_option'],
    'multiselect non-string item' => ['multiselect', $options, ['a', 1], 'invalid_option'],
    'multiselect null item' => ['multiselect', $options, [null], 'invalid_option'],
    'multiselect string' => ['multiselect', $options, 'a', 'type_mismatch'],
    'multiselect object' => ['multiselect', $options, fs_tree(['first' => 'a']), 'type_mismatch'],
    'multiselect non-list array' => ['multiselect', $options, [1 => 'a'], 'type_mismatch'],
    'checkbox true' => ['checkbox', [], true, null],
    'checkbox false' => ['checkbox', [], false, null],
    'checkbox string' => ['checkbox', [], 'true', 'type_mismatch'],
    'checkbox integer' => ['checkbox', [], 1, 'type_mismatch'],
    'toggle false' => ['toggle', [], false, null],
    'toggle string' => ['toggle', [], 'on', 'type_mismatch'],
    'photo id' => ['photo', [], $uuid, null],
    'photo uppercase id' => ['photo', [], strtoupper($uuid), null],
    'photo not a uuid' => ['photo', [], 'photo.jpg', 'invalid_attachment'],
    'photo list for single' => ['photo', [], [$uuid], 'invalid_attachment'],
    'photo number' => ['photo', [], 7, 'invalid_attachment'],
    'photo multiple false is single' => ['photo', ['multiple' => false], [$uuid], 'invalid_attachment'],
    'photo multiple list' => ['photo', ['multiple' => true], [$uuid, strtoupper($uuid)], null],
    'photo multiple single id' => ['photo', ['multiple' => true], $uuid, 'invalid_attachment'],
    'photo multiple non-list array' => ['photo', ['multiple' => true], [1 => $uuid], 'invalid_attachment'],
    'photo multiple bad item' => ['photo', ['multiple' => true], [$uuid, 'nope'], 'invalid_attachment'],
    'file id' => ['file', [], $uuid, null],
    'file uuid with newline' => ['file', [], $uuid."\n", 'invalid_attachment'],
    'file multiple object' => ['file', ['multiple' => true], fs_tree(['a' => $uuid]), 'invalid_attachment'],
    'hidden string' => ['hidden', [], 'abc', null],
    'hidden integer' => ['hidden', [], -3, null],
    'hidden boolean' => ['hidden', [], false, null],
    'hidden integral float' => ['hidden', [], 2.0, null],
    'hidden fractional float' => ['hidden', [], 1.5, 'type_mismatch'],
    'hidden 2^53 is safe' => ['hidden', [], 2 ** 53, null],
    'hidden beyond 2^53' => ['hidden', [], 2 ** 54, 'type_mismatch'],
    'hidden 2^53 + 1 rounds like a double' => ['hidden', [], 2 ** 53 + 1, null],
    'hidden infinite float' => ['hidden', [], INF, 'type_mismatch'],
    'hidden array' => ['hidden', [], ['a'], 'type_mismatch'],
    'hidden object' => ['hidden', [], new stdClass, 'type_mismatch'],
    'repeater list' => ['repeater', ['elements' => []], [new stdClass], null],
    'repeater empty list' => ['repeater', ['elements' => []], [], null],
    'repeater object' => ['repeater', ['elements' => []], new stdClass, 'type_mismatch'],
    'repeater non-list array' => ['repeater', ['elements' => []], [1 => new stdClass], 'type_mismatch'],
    'repeater string' => ['repeater', ['elements' => []], 'rows', 'type_mismatch'],
    'rating has no type check' => ['rating', [], 'five', null],
    'calculated has no type check' => ['calculated', ['expression' => 1], ['x'], null],
    'layout type has no type check' => ['heading', ['text' => 'H'], 'anything', null],
]);

test('signature and location shapes', function (string $type, mixed $value, ?string $code) use ($element): void {
    expect(TypeChecker::check($element($type), $value)?->code)->toBe($code);
})->with([
    'signature valid' => ['signature', fs_tree($signature), null],
    'signature empty signer name' => ['signature', fs_tree([...$signature, 'signerName' => '']), null],
    'signature string' => ['signature', $uuid, 'type_mismatch'],
    'signature list' => ['signature', [$uuid], 'type_mismatch'],
    'signature empty object' => ['signature', new stdClass, 'invalid_signature'],
    'signature missing signedAt' => ['signature', fs_tree(['attachment' => $uuid, 'signerName' => 'W']), 'invalid_signature'],
    'signature extra key' => ['signature', fs_tree([...$signature, 'note' => 'x']), 'invalid_signature'],
    'signature attachment not a uuid' => ['signature', fs_tree([...$signature, 'attachment' => 'sig.png']), 'invalid_signature'],
    'signature signer name not a string' => ['signature', fs_tree([...$signature, 'signerName' => null]), 'invalid_signature'],
    'signature signedAt without offset' => ['signature', fs_tree([...$signature, 'signedAt' => '2026-10-16T09:41:00']), 'invalid_signature'],
    'signature signedAt a date' => ['signature', fs_tree([...$signature, 'signedAt' => '2026-10-16']), 'invalid_signature'],
    'signature signedAt a number' => ['signature', fs_tree([...$signature, 'signedAt' => 1760600460]), 'invalid_signature'],
    'location valid' => ['location', fs_tree($location), null],
    'location integer coordinates' => ['location', fs_tree([...$location, 'lat' => 0, 'lng' => 0, 'accuracy' => 0]), null],
    'location lat 90' => ['location', fs_tree([...$location, 'lat' => 90]), null],
    'location lat -90' => ['location', fs_tree([...$location, 'lat' => -90.0]), null],
    'location lat 91' => ['location', fs_tree([...$location, 'lat' => 91]), 'invalid_location'],
    'location lat -90.5' => ['location', fs_tree([...$location, 'lat' => -90.5]), 'invalid_location'],
    'location lng 180' => ['location', fs_tree([...$location, 'lng' => 180]), null],
    'location lng -180' => ['location', fs_tree([...$location, 'lng' => -180]), null],
    'location lng 180.1' => ['location', fs_tree([...$location, 'lng' => 180.1]), 'invalid_location'],
    'location lng -181' => ['location', fs_tree([...$location, 'lng' => -181]), 'invalid_location'],
    'location large accuracy' => ['location', fs_tree([...$location, 'accuracy' => 100000]), null],
    'location negative accuracy' => ['location', fs_tree([...$location, 'accuracy' => -0.1]), 'invalid_location'],
    'location bool lat' => ['location', fs_tree([...$location, 'lat' => true]), 'invalid_location'],
    'location bool lng' => ['location', fs_tree([...$location, 'lng' => false]), 'invalid_location'],
    'location string lat' => ['location', fs_tree([...$location, 'lat' => '52.52']), 'invalid_location'],
    'location null accuracy' => ['location', fs_tree([...$location, 'accuracy' => null]), 'invalid_location'],
    'location bad capturedAt' => ['location', fs_tree([...$location, 'capturedAt' => 'now']), 'invalid_location'],
    'location missing accuracy' => ['location', fs_tree(['lat' => 1, 'lng' => 2, 'capturedAt' => '2026-10-16T09:41:00Z']), 'invalid_location'],
    'location extra key' => ['location', fs_tree([...$location, 'altitude' => 30]), 'invalid_location'],
    'location number' => ['location', 52.52, 'type_mismatch'],
    'location list' => ['location', [52.52, 13.4], 'type_mismatch'],
]);

test('SignatureLocation checks values without an element', function (): void {
    expect(SignatureLocation::checkSignature(null))->toEqual(new Violation('type_mismatch'))
        ->and(SignatureLocation::checkLocation('x'))->toEqual(new Violation('type_mismatch'))
        ->and(SignatureLocation::checkLocation((object) ['accuracy' => 1.5, 'capturedAt' => '2026-10-16T09:41:00Z', 'lat' => 0, 'lng' => 0]))->toBeNull()
        ->and(SignatureLocation::checkLocation((object) ['accuracy' => INF, 'capturedAt' => '2026-10-16T09:41:00Z', 'lat' => 0, 'lng' => 0])?->code)
        ->toBe('invalid_location')
        ->and(SignatureLocation::checkLocation((object) ['accuracy' => 1, 'capturedAt' => '2026-10-16T09:41:00Z', 'lat' => NAN, 'lng' => 0])?->code)
        ->toBe('invalid_location');
});

test('scale_exceeded reports the money scale as param', function (array $properties, string $value, int $scale) use ($element): void {
    expect(TypeChecker::check($element('money', ['currency' => 'EUR', ...$properties]), $value))
        ->toEqual(new Violation('scale_exceeded', ['scale' => $scale]));
})->with([
    'default scale' => [[], '1.001', 2],
    'scale 0' => [['scale' => 0], '1.0', 0],
    'scale 3' => [['scale' => 3], '0.0001', 3],
    'integral float scale' => [['scale' => 1.0], '0.25', 1],
    'scale 4' => [['scale' => 4], '0.00001', 4],
    'scale above 4 falls back to 2' => [['scale' => 5], '0.001', 2],
]);

test('other violations carry no params', function () use ($element): void {
    expect(TypeChecker::check($element('number'), 'x'))->toEqual(new Violation('invalid_number'))
        ->and(TypeChecker::check($element('text'), 1))->toEqual(new Violation('type_mismatch'));
});

test('isHiddenValue accepts strings, booleans and safe JSON integers only', function (mixed $value, bool $expected): void {
    expect(TypeChecker::isHiddenValue($value))->toBe($expected);
})->with([
    'empty string' => ['', true],
    'integer' => [0, true],
    'true' => [true, true],
    'integral float' => [-4.0, true],
    'unsafe integer' => [-(2 ** 60), false],
    'unsafe integral float' => [1e20, false],
    'fraction' => [0.5, false],
    'NaN' => [NAN, false],
    'null' => [null, false],
    'list' => [[], false],
    'object' => [new stdClass, false],
]);

test('isUuid accepts any version in either case', function (mixed $value, bool $expected): void {
    expect(TypeChecker::isUuid($value))->toBe($expected);
})->with([
    'v4 lowercase' => ['0b6e4d3c-8f1a-4c2b-9d7e-1a2b3c4d5e6f', true],
    'v1 uppercase' => ['A0B1C2D3-E4F5-1A2B-8C3D-4E5F6A7B8C9D', true],
    'nil uuid' => ['00000000-0000-0000-0000-000000000000', true],
    'without hyphens' => ['0b6e4d3c8f1a4c2b9d7e1a2b3c4d5e6f', false],
    'braced' => ['{0b6e4d3c-8f1a-4c2b-9d7e-1a2b3c4d5e6f}', false],
    'non-hex' => ['0b6e4d3c-8f1a-4c2b-9d7e-1a2b3c4d5e6g', false],
    'integer' => [1, false],
    'null' => [null, false],
]);
