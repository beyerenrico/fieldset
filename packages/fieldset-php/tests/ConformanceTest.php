<?php

declare(strict_types=1);

use Fieldset\Evaluation\Evaluator;
use Fieldset\Evaluation\ValidationError;
use Fieldset\Json\Json;
use Fieldset\Json\JsonPointer;
use Fieldset\Lint\Linter;
use Fieldset\Logic\JsonLogic;
use Fieldset\Validation\MetaSchemaValidator;

require_once __DIR__.'/Support/helpers.php';

/*
 * Runs every case of spec/conformance (assertions per spec/conformance/README.md).
 */

test('conformance logic', function (stdClass $suite, stdClass $case): void {
    $result = (new JsonLogic)->evaluate($case->logic, $case->data);

    expect(Json::canonical($result))->toBe(Json::canonical($case->expected));
})->with(fs_conformance_cases('logic'))->group('conformance');

test('conformance evaluate', function (stdClass $suite, stdClass $case): void {
    $context = fs_context();
    $result = (new Evaluator)->evaluate($suite->definition, $case->data, $context);
    $expected = $case->expected;

    expect(fs_error_set($result->errors))->toBe(fs_error_set($expected->errors));

    foreach ($expected->errors as $error) {
        if (! property_exists($error, 'params')) {
            continue;
        }

        $match = array_values(array_filter(
            $result->errors,
            static fn (ValidationError $e): bool => $e->pointer === $error->pointer && $e->code === $error->code,
        ));
        expect(Json::canonical($match[0]->params))->toBe(Json::canonical($error->params));
    }

    if (property_exists($expected, 'data')) {
        expect(Json::canonical($result->data))->toBe(Json::canonical($expected->data));
    }

    foreach (get_object_vars($expected->calculated ?? new stdClass) as $pointer => $value) {
        [$found, $actual] = JsonPointer::get($result->data, (string) $pointer);
        expect(Json::canonical($found ? $actual : null))->toBe(Json::canonical($value), "calculated {$pointer}");
    }

    foreach (get_object_vars($expected->visibility ?? new stdClass) as $pointer => $visible) {
        expect($result->visibility)->toHaveKey((string) $pointer);
        expect($result->visibility[(string) $pointer])->toBe($visible, "visibility {$pointer}");
    }
})->with(fs_conformance_cases('evaluate'))->group('conformance');

test('conformance lint', function (stdClass $suite, stdClass $case): void {
    static $linter = null;
    $linter ??= new Linter;
    $findings = $linter->lint($case->definition, $case->published ?? []);

    expect(fs_finding_set($findings))->toBe(fs_finding_set($case->expected));
})->with(fs_conformance_cases('lint'))->group('conformance');

test('conformance meta-schema', function (stdClass $suite, stdClass $case): void {
    static $validator = null;
    $validator ??= new MetaSchemaValidator;

    expect($validator->validate($case->definition)->valid)->toBe($case->valid);
})->with(fs_conformance_cases('meta-schema'))->group('conformance');

test('conformance checksum', function (stdClass $suite, stdClass $case): void {
    expect(Json::canonical($case->value))->toBe($case->canonical)
        ->and(Json::checksum($case->value))->toBe($case->sha256);
})->with(fs_conformance_cases('checksum'))->group('conformance');
