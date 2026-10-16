<?php

declare(strict_types=1);

use FormSchema\Evaluation\EvaluationContext;
use FormSchema\Evaluation\EvaluationResult;
use FormSchema\Evaluation\ValidationError;
use FormSchema\Evaluation\Violation;
use FormSchema\Json\Json;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Public value objects of the evaluation API: context, result, errors and violations.
 */

test('EvaluationContext keeps constructor values; optional ones default to null', function (): void {
    $context = new EvaluationContext('2026-10-16', '2026-10-16T09:41:00Z');

    expect($context->today)->toBe('2026-10-16')
        ->and($context->now)->toBe('2026-10-16T09:41:00Z')
        ->and($context->userName)->toBeNull()
        ->and($context->userEmail)->toBeNull()
        ->and($context->orgName)->toBeNull()
        ->and($context->formVersion)->toBeNull();
});

test('EvaluationContext::fromArray reads the conformance shape', function (): void {
    $context = EvaluationContext::fromArray([
        'today' => '2026-10-16',
        'now' => '2026-10-16T09:41:00+02:00',
        'user' => ['name' => 'Willi Worker', 'email' => 'worker@demo.test'],
        'org' => ['name' => 'Demo GmbH'],
        'formVersion' => 3,
    ]);

    expect($context)->toEqual(fs_context());
});

test('EvaluationContext::fromArray accepts stdClass maps for user and org', function (): void {
    $tree = fs_tree([
        'today' => '2026-01-31',
        'now' => '2026-01-31T23:59:59Z',
        'user' => ['name' => 'Anna', 'email' => 'anna@example.com'],
        'org' => ['name' => 'ACME'],
        'formVersion' => '2.1',
    ]);
    $context = EvaluationContext::fromArray(get_object_vars($tree));

    expect($context->userName)->toBe('Anna')
        ->and($context->userEmail)->toBe('anna@example.com')
        ->and($context->orgName)->toBe('ACME')
        ->and($context->formVersion)->toBe('2.1')
        ->and($context->today)->toBe('2026-01-31')
        ->and($context->now)->toBe('2026-01-31T23:59:59Z');
});

test('EvaluationContext::fromArray leaves missing today and now null (no clock fallback)', function (array $input): void {
    $context = EvaluationContext::fromArray($input);

    expect($context->today)->toBeNull()
        ->and($context->now)->toBeNull()
        ->and($context->builtins()->{'$today'})->toBeNull()
        ->and($context->builtins()->{'$now'})->toBeNull()
        ->and((new EvaluationContext)->today)->toBeNull();
})->with([
    'missing' => [[]],
    'not strings' => [['today' => 20261016, 'now' => null]],
]);

test('EvaluationContext::fromArray ignores malformed user, org and names', function (): void {
    $context = EvaluationContext::fromArray([
        'today' => '2026-10-16',
        'now' => '2026-10-16T09:41:00Z',
        'user' => 'Willi',
        'org' => ['name' => 42],
    ]);

    expect($context->userName)->toBeNull()
        ->and($context->userEmail)->toBeNull()
        ->and($context->orgName)->toBeNull();

    $partial = EvaluationContext::fromArray(['user' => fs_tree(['name' => 'Anna', 'email' => false])]);

    expect($partial->userName)->toBe('Anna')
        ->and($partial->userEmail)->toBeNull();
});

test('EvaluationContext::fromArray normalizes formVersion', function (mixed $version, int|string|null $expected): void {
    expect(EvaluationContext::fromArray(['formVersion' => $version])->formVersion)->toBe($expected);
})->with([
    'integer' => [7, 7],
    'string' => ['7', '7'],
    'integral float' => [3.0, 3],
    'negative integral float' => [-2.0, -2],
    'fractional float' => [3.5, null],
    'float beyond PHP_INT_MAX' => [1e30, null],
    'infinite float' => [INF, null],
    'bool' => [true, null],
    'list' => [[1], null],
    'null' => [null, null],
]);

test('EvaluationContext::fromArray without formVersion yields null', function (): void {
    expect(EvaluationContext::fromArray([])->formVersion)->toBeNull();
});

test('builtins() exposes the logic built-ins', function (): void {
    $builtins = fs_context()->builtins();

    expect(Json::canonical($builtins))->toBe(Json::canonical(fs_tree([
        '$today' => '2026-10-16',
        '$now' => '2026-10-16T09:41:00+02:00',
        '$user' => ['name' => 'Willi Worker', 'email' => 'worker@demo.test'],
        '$org' => ['name' => 'Demo GmbH'],
        '$form' => ['version' => 3],
    ])));
});

test('builtins() keeps unknown values as null and returns fresh objects', function (): void {
    $context = new EvaluationContext('2026-10-16', '2026-10-16T09:41:00Z');
    $first = $context->builtins();
    $second = $context->builtins();

    expect($first->{'$user'}->name)->toBeNull()
        ->and($first->{'$user'}->email)->toBeNull()
        ->and($first->{'$org'}->name)->toBeNull()
        ->and($first->{'$form'}->version)->toBeNull()
        ->and($first)->toEqual($second)
        ->and($first)->not->toBe($second)
        ->and($first->{'$user'})->not->toBe($second->{'$user'});
});

test('EvaluationResult::isValid is true only without errors', function (): void {
    $valid = new EvaluationResult(new stdClass, [], ['/a' => true]);
    $invalid = new EvaluationResult(new stdClass, [new ValidationError('/a', 'required')], ['/a' => true]);

    expect($valid->isValid())->toBeTrue()
        ->and($valid->errorsToArray())->toBe([])
        ->and($invalid->isValid())->toBeFalse()
        ->and($invalid->visibility)->toBe(['/a' => true]);
});

test('EvaluationResult::errorsToArray keeps order and omits empty params', function (): void {
    $data = fs_tree(['a' => 'x']);
    $result = new EvaluationResult($data, [
        new ValidationError('/b', 'max_length', ['limit' => 5]),
        new ValidationError('/a', 'required'),
    ], []);

    expect($result->data)->toBe($data)
        ->and($result->errorsToArray())->toBe([
            ['pointer' => '/b', 'code' => 'max_length', 'params' => ['limit' => 5]],
            ['pointer' => '/a', 'code' => 'required'],
        ]);
});

test('ValidationError::toArray and jsonSerialize without params', function (): void {
    $error = new ValidationError('/items/2/qty', 'type_mismatch');

    expect($error->params)->toBe([])
        ->and($error->toArray())->toBe(['pointer' => '/items/2/qty', 'code' => 'type_mismatch'])
        ->and($error->jsonSerialize())->toBe($error->toArray())
        ->and(json_encode($error, JSON_UNESCAPED_SLASHES))->toBe('{"pointer":"/items/2/qty","code":"type_mismatch"}');
});

test('ValidationError::toArray and jsonSerialize with params', function (): void {
    $error = new ValidationError('/price', 'scale_exceeded', ['scale' => 2]);

    expect($error->toArray())->toBe(['pointer' => '/price', 'code' => 'scale_exceeded', 'params' => ['scale' => 2]])
        ->and($error->jsonSerialize())->toBe($error->toArray())
        ->and(json_encode($error, JSON_UNESCAPED_SLASHES))->toBe('{"pointer":"/price","code":"scale_exceeded","params":{"scale":2}}');
});

test('Violation::at binds the violation to a pointer', function (): void {
    $withParams = (new Violation('min', ['limit' => '0.5']))->at('/items/0/qty');
    $withoutParams = (new Violation('required'))->at('/name');

    expect($withParams)->toEqual(new ValidationError('/items/0/qty', 'min', ['limit' => '0.5']))
        ->and($withoutParams)->toEqual(new ValidationError('/name', 'required'))
        ->and($withoutParams->params)->toBe([]);
});
