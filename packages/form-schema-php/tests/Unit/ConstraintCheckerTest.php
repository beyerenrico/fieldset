<?php

declare(strict_types=1);

use FormSchema\Definition\Definition;
use FormSchema\Definition\Element;
use FormSchema\Evaluation\ConstraintChecker;
use FormSchema\Evaluation\ElementValidator;
use FormSchema\Evaluation\Violation;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Constraint checks of spec §3.3 and the per-value validation order of §5.6.
 */

$element = static fn (string $type, array $properties = []): Element => Definition::fromJson(fs_definition([fs_element($type, 'field', ['label' => 'Field', ...$properties])]))->elements[0];

$rules = static fn (array $validation): array => ['validation' => $validation];

$uuid = '0b6e4d3c-8f1a-4c2b-9d7e-1a2b3c4d5e6f';

test('length and pattern constraints', function (string $type, array $properties, mixed $value, ?Violation $expected) use ($element): void {
    expect(ConstraintChecker::check($element($type, $properties), $value))->toEqual($expected);
})->with([
    'text below minLength' => ['text', $rules(['minLength' => 3]), 'ab', new Violation('min_length', ['limit' => 3])],
    'text at minLength' => ['text', $rules(['minLength' => 3]), 'abc', null],
    'text above maxLength' => ['text', $rules(['maxLength' => 2]), 'abc', new Violation('max_length', ['limit' => 2])],
    'umlauts count as one code point each' => ['text', $rules(['minLength' => 3, 'maxLength' => 3]), 'äöü', null],
    'astral characters count as one code point' => ['text', $rules(['maxLength' => 1]), '😀', null],
    'two astral characters exceed maxLength 1' => ['textarea', $rules(['maxLength' => 1]), '😀😀', new Violation('max_length', ['limit' => 1])],
    'integral float minLength is enforced' => ['text', $rules(['minLength' => 3.0]), 'ab', new Violation('min_length', ['limit' => 3])],
    'integral float maxLength is enforced' => ['text', $rules(['maxLength' => 1.0]), 'ab', new Violation('max_length', ['limit' => 1])],
    'fractional minLength is ignored' => ['text', $rules(['minLength' => 2.5]), 'a', null],
    'minLength as a string is ignored' => ['text', $rules(['minLength' => '3']), 'a', null],
    'length ignores non-string values' => ['text', $rules(['minLength' => 3]), 12, null],
    'minLength is checked before pattern' => ['text', $rules(['minLength' => 5, 'pattern' => '^[0-9]+$']), 'ab', new Violation('min_length', ['limit' => 5])],
    'pattern matches' => ['text', $rules(['pattern' => '^[A-Z]{2}-[0-9]+$']), 'AB-12', null],
    'pattern fails' => ['textarea', $rules(['pattern' => '^[A-Z]{2}-[0-9]+$']), 'ab-12', new Violation('pattern')],
    'pattern is implicitly unanchored' => ['text', $rules(['pattern' => '[0-9]']), 'abc1def', null],
    'pattern without match anywhere' => ['text', $rules(['pattern' => '[0-9]']), 'abcdef', new Violation('pattern')],
    'pattern that is not a string is ignored' => ['text', $rules(['pattern' => 5]), 'abc', null],
    'pattern ignores non-string values' => ['text', $rules(['pattern' => '^a$']), ['b'], null],
    'phone maxLength' => ['phone', $rules(['maxLength' => 5]), '+49 30 1234', new Violation('max_length', ['limit' => 5])],
    'phone pattern' => ['phone', $rules(['pattern' => '^\+49']), '030 1234', new Violation('pattern')],
    'email minLength' => ['email', $rules(['minLength' => 10]), 'a@b.de', new Violation('min_length', ['limit' => 10])],
    'email ignores pattern' => ['email', $rules(['pattern' => '^x']), 'a@b.de', null],
    'no validation object' => ['text', [], 'anything', null],
    'validation that is not an object' => ['text', ['validation' => 'strict'], 'anything', null],
]);

test('number and money bounds and step', function (string $type, array $validation, mixed $value, ?Violation $expected) use ($element): void {
    $properties = ['validation' => $validation, ...($type === 'money' ? ['currency' => 'EUR'] : [])];

    expect(ConstraintChecker::check($element($type, $properties), $value))->toEqual($expected);
})->with([
    'below JSON number min' => ['number', ['min' => 10], '9.5', new Violation('min', ['limit' => 10])],
    'at JSON number min' => ['number', ['min' => 10], '10.000', null],
    'below decimal string min' => ['number', ['min' => '0.5'], '0.49', new Violation('min', ['limit' => '0.5'])],
    'below fractional JSON min' => ['number', ['min' => 0.1], '0.09', new Violation('min', ['limit' => 0.1])],
    'fractional JSON min is exact' => ['number', ['min' => 0.1], '0.1', null],
    'above decimal string max' => ['money', ['max' => '100.50'], '100.51', new Violation('max', ['limit' => '100.50'])],
    'at decimal string max' => ['money', ['max' => '100.50'], '100.5', null],
    'above JSON number max' => ['number', ['max' => -1], '0', new Violation('max', ['limit' => -1])],
    'negative values against min' => ['number', ['min' => -5], '-5.01', new Violation('min', ['limit' => -5])],
    'invalid min is ignored' => ['number', ['min' => 'ten'], '1', null],
    'bool max is ignored' => ['number', ['max' => true], '1000', null],
    'min is reported before step' => ['number', ['min' => 1, 'step' => 2], '0', new Violation('min', ['limit' => 1])],
    'max is reported before step' => ['number', ['max' => 1, 'step' => 2], '3', new Violation('max', ['limit' => 1])],
    'step without min is relative to zero' => ['number', ['step' => 0.25], '1.75', null],
    'step without min violated' => ['number', ['step' => 0.25], '1.8', new Violation('step', ['step' => 0.25])],
    'step on negative values' => ['number', ['step' => 0.25], '-0.75', null],
    'step relative to min' => ['number', ['min' => 1, 'step' => 2], '3', null],
    'step relative to min violated' => ['number', ['min' => 1, 'step' => 2], '4', new Violation('step', ['step' => 2])],
    'decimal string step and min' => ['money', ['min' => '0.25', 'step' => '0.5'], '1.25', null],
    'decimal string step violated' => ['money', ['min' => '0.25', 'step' => '0.5'], '1.50', new Violation('step', ['step' => '0.5'])],
    'fractional JSON step is exact' => ['number', ['step' => 0.1], '0.3', null],
    'zero step is ignored' => ['number', ['step' => 0], '0.3', null],
    'negative step is ignored' => ['number', ['step' => -1], '0.3', null],
    'invalid step is ignored' => ['number', ['step' => '1e2'], '0.3', null],
    'invalid min still allows step from zero' => ['number', ['min' => 'x', 'step' => 2], '3', new Violation('step', ['step' => 2])],
    'JSON integer values are compared' => ['number', ['min' => 10], 5, new Violation('min', ['limit' => 10])],
    'non-decimal values are skipped' => ['number', ['min' => 10], 'abc', null],
    'fractional JSON values are skipped' => ['number', ['min' => 10], 1.5, null],
]);

test('date, datetime and time bounds', function (string $type, array $validation, mixed $value, ?Violation $expected) use ($element): void {
    expect(ConstraintChecker::check($element($type, ['validation' => $validation]), $value))->toEqual($expected);
})->with([
    'date before min' => ['date', ['min' => '2026-01-01'], '2025-12-31', new Violation('min', ['limit' => '2026-01-01'])],
    'date at min' => ['date', ['min' => '2026-01-01', 'max' => '2026-12-31'], '2026-01-01', null],
    'date at max' => ['date', ['min' => '2026-01-01', 'max' => '2026-12-31'], '2026-12-31', null],
    'date after max' => ['date', ['max' => '2026-12-31'], '2027-01-01', new Violation('max', ['limit' => '2026-12-31'])],
    'unreal date bound is ignored' => ['date', ['min' => '2026-02-30'], '2000-01-01', null],
    'numeric date bound is ignored' => ['date', ['max' => 20260101], '2030-01-01', null],
    'invalid date value is skipped' => ['date', ['min' => '2026-01-01'], '2020-02-30', null],
    'datetime equal instant in another offset' => ['datetime', ['max' => '2026-10-16T07:00:00Z'], '2026-10-16T09:00:00+02:00', null],
    'datetime one second after max' => ['datetime', ['max' => '2026-10-16T07:00:00Z'], '2026-10-16T09:00:01+02:00', new Violation('max', ['limit' => '2026-10-16T07:00:00Z'])],
    'datetime before min across offsets' => ['datetime', ['min' => '2026-10-16T10:00:00+03:00'], '2026-10-16T06:59:59.999Z', new Violation('min', ['limit' => '2026-10-16T10:00:00+03:00'])],
    'datetime later local time but earlier instant' => ['datetime', ['max' => '2026-10-16T08:00:00Z'], '2026-10-16T09:30:00+02:00', null],
    'datetime fraction beyond microseconds' => ['datetime', ['max' => '2026-10-16T07:00:00.0000001Z'], '2026-10-16T07:00:00.0000002Z', new Violation('max', ['limit' => '2026-10-16T07:00:00.0000001Z'])],
    'datetime tiny fraction after max' => ['datetime', ['max' => '2026-10-16T07:00:00Z'], '2026-10-16T09:00:00.000000001+02:00', new Violation('max', ['limit' => '2026-10-16T07:00:00Z'])],
    'datetime equal fractions with trailing zeros' => ['datetime', ['min' => '2026-10-16T07:00:00.5Z'], '2026-10-16T07:00:00.500Z', null],
    'datetime bound without offset is ignored' => ['datetime', ['max' => '2026-10-16T07:00:00'], '2030-01-01T00:00:00Z', null],
    'invalid datetime value is skipped' => ['datetime', ['min' => '2026-10-16T07:00:00Z'], '2020-01-01', null],
    'time before min' => ['time', ['min' => '08:00'], '07:59:59', new Violation('min', ['limit' => '08:00'])],
    'time at min with seconds' => ['time', ['min' => '08:00'], '08:00:00', null],
    'time after max' => ['time', ['max' => '17:30'], '17:30:01', new Violation('max', ['limit' => '17:30'])],
    'invalid time bound is ignored' => ['time', ['min' => '8:00'], '00:00', null],
    'invalid time value is skipped' => ['time', ['min' => '08:00'], '25:00', null],
]);

test('multiselect counts, maxFiles and rating', function (string $type, array $properties, mixed $value, ?Violation $expected) use ($element): void {
    expect(ConstraintChecker::check($element($type, $properties), $value))->toEqual($expected);
})->with([
    'multiselect below min' => ['multiselect', $rules(['min' => 2]), ['a'], new Violation('min', ['limit' => 2])],
    'multiselect at min' => ['multiselect', $rules(['min' => 2]), ['a', 'b'], null],
    'multiselect above max' => ['multiselect', $rules(['max' => 1]), ['a', 'b'], new Violation('max', ['limit' => 1])],
    'multiselect decimal string bound is ignored' => ['multiselect', $rules(['min' => '2', 'max' => '0']), ['a'], null],
    'multiselect integral float bound' => ['multiselect', $rules(['max' => 1.0]), ['a', 'b'], new Violation('max', ['limit' => 1.0])],
    'multiselect fractional bound' => ['multiselect', $rules(['min' => 1.5]), ['a'], new Violation('min', ['limit' => 1.5])],
    'multiselect non-array counts as zero' => ['multiselect', $rules(['min' => 1]), 'a', new Violation('min', ['limit' => 1])],
    'multiselect invalid bounds are ignored' => ['multiselect', $rules(['min' => 'two', 'max' => null]), ['a', 'b', 'c'], null],
    'maxFiles exceeded' => ['photo', ['multiple' => true, ...$rules(['maxFiles' => 2])], [$uuid, $uuid, $uuid], new Violation('max_items', ['limit' => 2])],
    'maxFiles reached' => ['file', ['multiple' => true, ...$rules(['maxFiles' => 2])], [$uuid, $uuid], null],
    'maxFiles ignored for single attachments' => ['photo', $rules(['maxFiles' => 1]), [$uuid, $uuid], null],
    'integral float maxFiles' => ['photo', ['multiple' => true, ...$rules(['maxFiles' => 1.0])], [$uuid, $uuid], new Violation('max_items', ['limit' => 1])],
    'maxFiles that is not an integer is ignored' => ['photo', ['multiple' => true, ...$rules(['maxFiles' => '1'])], [$uuid, $uuid], null],
    'maxFiles ignores non-array values' => ['photo', ['multiple' => true, ...$rules(['maxFiles' => 0])], $uuid, null],
    'rating default max accepts 5' => ['rating', [], 5, null],
    'rating accepts 1' => ['rating', [], 1, null],
    'rating accepts integral float 4.0' => ['rating', [], 4.0, null],
    'rating 0' => ['rating', [], 0, new Violation('out_of_range')],
    'rating 6 above default max' => ['rating', [], 6, new Violation('out_of_range')],
    'rating fraction' => ['rating', [], 4.5, new Violation('out_of_range')],
    'rating numeric string' => ['rating', [], '3', new Violation('out_of_range')],
    'rating bool' => ['rating', [], true, new Violation('out_of_range')],
    'rating beyond safe integers' => ['rating', ['max' => 10], 2 ** 60, new Violation('out_of_range')],
    'rating custom max 10' => ['rating', ['max' => 10], 10, null],
    'rating above custom max' => ['rating', ['max' => 10], 11, new Violation('out_of_range')],
    'rating integral float max accepts 7' => ['rating', ['max' => 7.0], 7, null],
    'rating integral float max' => ['rating', ['max' => 7.0], 8, new Violation('out_of_range')],
    'rating invalid max falls back to 5' => ['rating', ['max' => 'ten'], 6, new Violation('out_of_range')],
    'rating invalid max still accepts 5' => ['rating', ['max' => 'ten'], 5, null],
    'types without constraints' => ['checkbox', $rules(['min' => 1]), false, null],
    'select has no constraints' => ['select', $rules(['minLength' => 5]), 'a', null],
]);

test('ElementValidator: required, type, then constraints', function (string $type, array $properties, mixed $value, bool $required, ?Violation $expected) use ($element): void {
    expect(ElementValidator::validate($element($type, $properties), $value, $required))->toEqual($expected);
})->with([
    'optional null' => ['text', [], null, false, null],
    'required null' => ['text', [], null, true, new Violation('required')],
    'required empty string' => ['text', [], '', true, new Violation('required')],
    'required empty list' => ['multiselect', [], [], true, new Violation('required')],
    'optional empty list skips min count' => ['multiselect', $rules(['min' => 1]), [], false, null],
    'whitespace is not empty' => ['text', $rules(['minLength' => 2]), ' ', true, new Violation('min_length', ['limit' => 2])],
    'type check before constraints' => ['text', $rules(['minLength' => 3]), 5, false, new Violation('type_mismatch')],
    'constraints after type check' => ['text', $rules(['minLength' => 3]), 'ab', false, new Violation('min_length', ['limit' => 3])],
    'required checkbox false' => ['checkbox', [], false, true, new Violation('required')],
    'required checkbox true' => ['checkbox', [], true, true, null],
    'optional checkbox false' => ['checkbox', [], false, false, null],
    'required checkbox type mismatch first' => ['checkbox', [], 'yes', true, new Violation('type_mismatch')],
    'required toggle false is fine' => ['toggle', [], false, true, null],
    'zero is not empty' => ['rating', [], 0, true, new Violation('out_of_range')],
    'empty object is not empty' => ['location', [], new stdClass, true, new Violation('invalid_location')],
    'repeater required without rows' => ['repeater', ['elements' => []], [], true, new Violation('required')],
    'repeater required null' => ['repeater', ['elements' => []], null, true, new Violation('required')],
    'repeater required reports required only' => ['repeater', ['elements' => [], 'minItems' => 2], null, true, new Violation('required')],
    'repeater minItems without rows' => ['repeater', ['elements' => [], 'minItems' => 1], null, false, new Violation('min_items', ['limit' => 1])],
    'repeater minItems with rows' => ['repeater', ['elements' => [], 'minItems' => 2], [[]], true, new Violation('min_items', ['limit' => 2])],
    'repeater maxItems' => ['repeater', ['elements' => [], 'maxItems' => 1], [[], []], false, new Violation('max_items', ['limit' => 1])],
    'repeater within bounds' => ['repeater', ['elements' => [], 'minItems' => 1, 'maxItems' => 2], [[]], false, null],
    'repeater integral float minItems' => ['repeater', ['elements' => [], 'minItems' => 1.0], null, false, new Violation('min_items', ['limit' => 1])],
    'repeater integral float maxItems' => ['repeater', ['elements' => [], 'maxItems' => 1.0], [[], []], false, new Violation('max_items', ['limit' => 1])],
    'repeater non-integer bounds are ignored' => ['repeater', ['elements' => [], 'minItems' => '3'], [], false, null],
    'repeater non-array counts as zero' => ['repeater', ['elements' => [], 'minItems' => 1], 'rows', false, new Violation('min_items', ['limit' => 1])],
]);

test('ElementValidator::isEmpty', function (mixed $value, bool $expected): void {
    expect(ElementValidator::isEmpty($value))->toBe($expected);
})->with([
    'null' => [null, true],
    'empty string' => ['', true],
    'empty list' => [[], true],
    'zero' => [0, false],
    'string zero' => ['0', false],
    'false' => [false, false],
    'space' => [' ', false],
    'empty object' => [new stdClass, false],
]);
