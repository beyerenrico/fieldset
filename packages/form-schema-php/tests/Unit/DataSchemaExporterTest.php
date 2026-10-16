<?php

declare(strict_types=1);

use FormSchema\Definition\Definition;
use FormSchema\Definition\InvalidDefinitionException;
use FormSchema\Export\DataSchemaExporter;
use FormSchema\Json\Json;
use Opis\JsonSchema\CompliantValidator;

require_once __DIR__.'/../Support/helpers.php';

$decimal = ['type' => 'string', 'pattern' => DataSchemaExporter::DECIMAL_PATTERN];
$string = ['type' => 'string'];
$uuid = ['type' => 'string', 'format' => 'uuid'];
$dateTime = ['type' => 'string', 'format' => 'date-time'];
$options = ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]];

$export = static fn (array $elements, array $extra = []): stdClass => (new DataSchemaExporter)->export(fs_definition($elements, $extra));

test('exports one schema per element type', function (string $type, array $properties, array $expected) use ($export): void {
    $schema = $export([fs_element($type, 'field', $properties)]);

    expect(Json::canonical($schema->properties->field))->toBe(Json::canonical(fs_tree($expected)));
})->with([
    'text' => ['text', [], $string],
    'textarea' => ['textarea', ['rows' => 3], $string],
    'phone' => ['phone', [], ['type' => 'string', 'pattern' => DataSchemaExporter::PHONE_PATTERN]],
    'email' => ['email', [], ['type' => 'string', 'format' => 'email']],
    'number' => ['number', [], $decimal],
    'money' => ['money', ['currency' => 'EUR'], $decimal],
    'date' => ['date', [], ['type' => 'string', 'format' => 'date']],
    'datetime' => ['datetime', [], $dateTime],
    'time' => ['time', [], ['type' => 'string', 'pattern' => DataSchemaExporter::TIME_PATTERN]],
    'select' => ['select', $options, ['type' => 'string', 'enum' => ['a', 'b']]],
    'radio' => ['radio', $options, ['type' => 'string', 'enum' => ['a', 'b']]],
    'radio without options' => ['radio', [], ['type' => 'string', 'enum' => []]],
    'multiselect' => ['multiselect', $options, ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b']], 'uniqueItems' => true]],
    'checkbox' => ['checkbox', [], ['type' => 'boolean']],
    'toggle' => ['toggle', [], ['type' => 'boolean']],
    'rating default max' => ['rating', [], ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]],
    'rating max' => ['rating', ['max' => 7], ['type' => 'integer', 'minimum' => 1, 'maximum' => 7]],
    'rating integral float max' => ['rating', ['max' => 10.0], ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
    'single photo' => ['photo', [], $uuid],
    'single file with multiple false' => ['file', ['multiple' => false], $uuid],
    'multiple files' => ['file', ['multiple' => true], ['type' => 'array', 'items' => $uuid]],
    'signature' => ['signature', [], [
        'type' => 'object',
        'properties' => ['attachment' => $uuid, 'signerName' => ['type' => 'string'], 'signedAt' => $dateTime],
        'required' => ['attachment', 'signerName', 'signedAt'],
        'additionalProperties' => false,
    ]],
    'location' => ['location', [], [
        'type' => 'object',
        'properties' => [
            'lat' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
            'lng' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
            'accuracy' => ['type' => 'number', 'minimum' => 0],
            'capturedAt' => $dateTime,
        ],
        'required' => ['lat', 'lng', 'accuracy', 'capturedAt'],
        'additionalProperties' => false,
    ]],
    'calculated number' => ['calculated', ['expression' => 1], ['type' => ['string', 'null'], 'pattern' => DataSchemaExporter::DECIMAL_PATTERN]],
    'calculated money' => ['calculated', ['expression' => 1, 'format' => 'money'], ['type' => ['string', 'null'], 'pattern' => DataSchemaExporter::DECIMAL_PATTERN]],
    'calculated text' => ['calculated', ['expression' => 'x', 'format' => 'text'], ['type' => ['string', 'null']]],
    'hidden' => ['hidden', [], ['type' => ['string', 'integer', 'boolean']]],
    'empty repeater' => ['repeater', ['elements' => []], ['type' => 'array', 'items' => ['type' => 'object', 'properties' => new stdClass, 'additionalProperties' => false]]],
    'repeater rows' => ['repeater', ['elements' => [fs_element('number', 'qty'), fs_element('heading', 'h', ['text' => 'H']), fs_element('text', 'note')]], [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => ['qty' => $decimal, 'note' => $string], 'additionalProperties' => false],
    ]],
]);

test('the root schema describes an object of all root data keys', function () use ($export, $string, $decimal): void {
    $schema = $export([
        fs_element('heading', 'intro', ['text' => 'Hi']),
        fs_element('text', 'name'),
        fs_element('section', 'box', ['elements' => [fs_element('number', 'qty')]]),
        fs_element('columns', 'cols', ['columns' => [['elements' => [fs_element('text', 'left')]], ['elements' => [fs_element('text', 'right')]]]]),
        fs_element('number', 'name'),
        fs_element('repeater', 'items', ['elements' => [fs_element('text', 'name'), fs_element('repeater', 'parts', ['elements' => [fs_element('number', 'serial')]])]]),
    ]);

    expect($schema->{'$schema'})->toBe(DataSchemaExporter::DRAFT)
        ->and($schema->title)->toBe('Test')
        ->and($schema->type)->toBe('object')
        ->and($schema->additionalProperties)->toBeFalse()
        ->and(array_keys(get_object_vars($schema)))->toBe(['$schema', 'title', 'type', 'properties', 'additionalProperties'])
        ->and(array_keys(get_object_vars($schema->properties)))->toBe(['name', 'qty', 'left', 'right', 'items'])
        ->and(Json::canonical($schema->properties->name))->toBe(Json::canonical(fs_tree($string)))
        ->and(array_keys(get_object_vars($schema->properties->items->items->properties)))->toBe(['name', 'parts'])
        ->and(Json::canonical($schema->properties->items->items->properties->parts->items->properties->serial))
        ->toBe(Json::canonical(fs_tree($decimal)));
});

test('string labels and roles become title and x-role annotations', function () use ($export): void {
    $schema = $export([
        fs_element('text', 'a', ['label' => 'First name', 'role' => 'customer.name']),
        fs_element('text', 'b', ['label' => 7, 'role' => ['x']]),
        fs_element('repeater', 'r', ['label' => 'Rows', 'elements' => [fs_element('number', 'q', ['label' => 'Qty', 'role' => 'line_item.qty'])]]),
    ]);

    expect(array_keys(get_object_vars($schema->properties->a)))->toBe(['type', 'title', 'x-role'])
        ->and($schema->properties->a->title)->toBe('First name')
        ->and($schema->properties->a->{'x-role'})->toBe('customer.name')
        ->and(array_keys(get_object_vars($schema->properties->b)))->toBe(['type'])
        ->and($schema->properties->r->title)->toBe('Rows')
        ->and(property_exists($schema->properties->r, 'x-role'))->toBeFalse()
        ->and($schema->properties->r->items->properties->q->title)->toBe('Qty')
        ->and($schema->properties->r->items->properties->q->{'x-role'})->toBe('line_item.qty');
});

test('the root title is omitted unless the definition title is a string', function (array $root): void {
    $schema = (new DataSchemaExporter)->export(fs_tree($root));

    expect(property_exists($schema, 'title'))->toBeFalse()
        ->and(array_keys(get_object_vars($schema)))->toBe(['$schema', 'type', 'properties', 'additionalProperties'])
        ->and($schema->properties)->toEqual(new stdClass);
})->with([
    'missing title' => [['pages' => []]],
    'numeric title' => [['title' => 5, 'pages' => []]],
]);

test('export accepts a Definition and rejects non-objects', function (): void {
    $json = fs_definition([fs_element('toggle', 't')]);
    $fromDefinition = (new DataSchemaExporter)->export(Definition::fromJson($json));

    expect(Json::canonical($fromDefinition))->toBe(Json::canonical((new DataSchemaExporter)->export($json)))
        ->and(fn () => (new DataSchemaExporter)->export('nope'))->toThrow(InvalidDefinitionException::class);
});

test('exported schemas describe non-empty values', function () use ($export): void {
    $schema = $export([
        fs_element('number', 'amount'),
        fs_element('multiselect', 'tags', ['options' => [['value' => 'x', 'label' => 'X']]]),
        fs_element('signature', 'sig'),
        fs_element('repeater', 'rows', ['elements' => [fs_element('checkbox', 'done')]]),
        fs_element('calculated', 'total', ['expression' => 1]),
        fs_element('hidden', 'ref'),
        fs_element('phone', 'phone'),
    ]);
    $validator = new CompliantValidator;
    $valid = static fn (array|stdClass $data): bool => $validator->validate(fs_tree($data), $schema)->isValid();

    expect($valid(new stdClass))->toBeTrue()
        ->and($valid(['amount' => '1.50', 'tags' => ['x'], 'rows' => [['done' => true], new stdClass], 'total' => null, 'ref' => 3, 'phone' => '+49 30 1234-56']))->toBeTrue()
        ->and($valid(['total' => '12.000000']))->toBeTrue()
        ->and($valid(['total' => 12]))->toBeFalse()
        ->and($valid(['amount' => null]))->toBeFalse()
        ->and($valid(['amount' => 1.5]))->toBeFalse()
        ->and($valid(['amount' => '1e3']))->toBeFalse()
        ->and($valid(['ref' => null]))->toBeFalse()
        ->and($valid(['phone' => 'call me']))->toBeFalse()
        ->and($valid(['tags' => ['x', 'x']]))->toBeFalse()
        ->and($valid(['tags' => ['y']]))->toBeFalse()
        ->and($valid(['sig' => ['attachment' => '0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b6c']]))->toBeFalse()
        ->and($valid(['rows' => [['done' => true, 'extra' => 1]]]))->toBeFalse()
        ->and($valid(['unknown' => 'x']))->toBeFalse();
});

test('expected conformance data validates against the exported schema', function (stdClass $suite, stdClass $case): void {
    $schema = (new DataSchemaExporter)->export($suite->definition);
    $result = (new CompliantValidator)->validate($case->expected->data, $schema);

    expect($result->isValid())->toBeTrue();
})->with(array_filter(
    fs_conformance_cases('evaluate'),
    static fn (array $entry): bool => property_exists($entry[1]->expected, 'data'),
));
