<?php

declare(strict_types=1);

use FormSchema\Definition\Definition;
use FormSchema\Definition\LogicReferences;
use FormSchema\Definition\Reference;
use FormSchema\Definition\ReferenceResolver;

require_once __DIR__.'/../Support/helpers.php';

$name = '/pages/0/elements/0';
$amount = '/pages/0/elements/1';
$sig = '/pages/0/elements/2';
$loc = '/pages/0/elements/3';
$tags = '/pages/0/elements/4';
$pics = '/pages/0/elements/5';
$doc = '/pages/0/elements/6';
$inner = '/pages/0/elements/7/elements/0';
$items = '/pages/0/elements/8';
$qty = $items.'/elements/0';
$rowName = $items.'/elements/1';
$parts = $items.'/elements/2';
$serial = $parts.'/elements/0';

beforeEach(function (): void {
    $this->definition = Definition::fromJson(fs_definition([
        fs_element('text', 'name', ['label' => 'Name']),
        fs_element('number', 'amount', ['label' => 'Amount']),
        fs_element('signature', 'sig', ['label' => 'Sig']),
        fs_element('location', 'loc', ['label' => 'Loc']),
        fs_element('multiselect', 'tags', ['label' => 'Tags', 'options' => [['value' => 'a', 'label' => 'A']]]),
        fs_element('photo', 'pics', ['label' => 'Pics', 'multiple' => true]),
        fs_element('file', 'doc', ['label' => 'Doc']),
        fs_element('section', 'box', ['elements' => [fs_element('text', 'inner', ['label' => 'Inner'])]]),
        fs_element('repeater', 'items', ['label' => 'Items', 'elements' => [
            fs_element('number', 'qty', ['label' => 'Qty']),
            fs_element('text', 'name', ['label' => 'Row name']),
            fs_element('repeater', 'parts', ['label' => 'Parts', 'elements' => [
                fs_element('text', 'serial', ['label' => 'Serial']),
            ]]),
        ]]),
    ]));
    $this->resolver = new ReferenceResolver($this->definition);
});

test('resolves var paths by definition', function (string $path, string $scope, string $kind, ?string $element, ?string $target): void {
    $reference = $this->resolver->resolve($path, $scope);

    expect($reference->kind)->toBe($kind)
        ->and($reference->element?->path)->toBe($element)
        ->and($reference->target?->path)->toBe($target)
        ->and($reference->isKnown())->toBe($kind !== Reference::UNKNOWN);
})->with([
    'whole scope' => ['', '', Reference::SCOPE, null, null],
    'whole scope in a row' => ['', $items, Reference::SCOPE, null, null],
    '$root alone' => ['$root', $items, Reference::SCOPE, null, null],
    '$root key' => ['$root.name', $items, Reference::ELEMENT, $name, $name],
    '$root key inside a section' => ['$root.inner', '', Reference::ELEMENT, $inner, $inner],
    '$root does not see row keys' => ['$root.qty', $items, Reference::UNKNOWN, null, null],
    '$root unknown key' => ['$root.nope', '', Reference::UNKNOWN, null, null],
    '$root into a row' => ['$root.items.0.qty', $parts, Reference::ELEMENT, $items, $qty],
    '$row at root' => ['$row', '', Reference::UNKNOWN, null, null],
    '$row key at root' => ['$row.name', '', Reference::UNKNOWN, null, null],
    '$row in a row' => ['$row', $items, Reference::SCOPE, null, null],
    '$row key in a row' => ['$row.qty', $items, Reference::ELEMENT, $qty, $qty],
    '$row does not see outer keys' => ['$row.amount', $items, Reference::UNKNOWN, null, null],
    '$row in a nested row' => ['$row.serial', $parts, Reference::ELEMENT, $serial, $serial],
    '$row sub-path' => ['$row.parts.0.serial', $items, Reference::ELEMENT, $parts, $serial],
    '$today' => ['$today', '', Reference::BUILTIN, null, null],
    '$today sub-property' => ['$today.year', '', Reference::UNKNOWN, null, null],
    '$now' => ['$now', $items, Reference::BUILTIN, null, null],
    '$user' => ['$user', '', Reference::BUILTIN, null, null],
    '$user.name' => ['$user.name', '', Reference::BUILTIN, null, null],
    '$user.email' => ['$user.email', '', Reference::BUILTIN, null, null],
    '$user unknown sub-property' => ['$user.phone', '', Reference::UNKNOWN, null, null],
    '$user too deep' => ['$user.name.first', '', Reference::UNKNOWN, null, null],
    '$org.name' => ['$org.name', '', Reference::BUILTIN, null, null],
    '$org unknown sub-property' => ['$org.id', '', Reference::UNKNOWN, null, null],
    '$form.version' => ['$form.version', '', Reference::BUILTIN, null, null],
    '$form unknown sub-property' => ['$form.key', '', Reference::UNKNOWN, null, null],
    'unknown $ name' => ['$x', '', Reference::UNKNOWN, null, null],
    'unknown $ name with path' => ['$x.y', '', Reference::UNKNOWN, null, null],
    'root key' => ['name', '', Reference::ELEMENT, $name, $name],
    'unknown key' => ['nope', '', Reference::UNKNOWN, null, null],
    'unknown key with sub-path' => ['nope.0.qty', '', Reference::UNKNOWN, null, null],
    'sub-path of a text value' => ['name.first', '', Reference::ELEMENT, $name, null],
    'row key shadows root key' => ['name', $items, Reference::ELEMENT, $rowName, $rowName],
    'root key from a row' => ['amount', $items, Reference::ELEMENT, $amount, $amount],
    'outer row key from a nested row' => ['qty', $parts, Reference::ELEMENT, $qty, $qty],
    'row key at root' => ['qty', '', Reference::UNKNOWN, null, null],
    'signature property' => ['sig.signerName', '', Reference::ELEMENT, $sig, null],
    'signature unknown property' => ['sig.foo', '', Reference::ELEMENT, $sig, null],
    'location property' => ['loc.lat', '', Reference::ELEMENT, $loc, null],
    'multiselect index' => ['tags.0', '', Reference::ELEMENT, $tags, null],
    'multiselect non-index' => ['tags.x', '', Reference::ELEMENT, $tags, null],
    'multiple photo index' => ['pics.1', '', Reference::ELEMENT, $pics, null],
    'single file index' => ['doc.0', '', Reference::ELEMENT, $doc, null],
    'repeater' => ['items', '', Reference::ELEMENT, $items, $items],
    'repeater row' => ['items.0', '', Reference::ELEMENT, $items, null],
    'repeater row key' => ['items.0.qty', '', Reference::ELEMENT, $items, $qty],
    'repeater row key with a large index' => ['items.12.qty', '', Reference::ELEMENT, $items, $qty],
    'repeater row key below a value' => ['items.0.qty.x', '', Reference::ELEMENT, $items, null],
    'repeater row unknown key' => ['items.0.nope', '', Reference::ELEMENT, $items, null],
    'repeater row does not see root keys' => ['items.0.amount', '', Reference::ELEMENT, $items, null],
    'repeater non-index segment' => ['items.first.qty', '', Reference::ELEMENT, $items, null],
    'repeater index with leading zero' => ['items.01.qty', '', Reference::ELEMENT, $items, null],
    'repeater negative index' => ['items.-1.qty', '', Reference::ELEMENT, $items, null],
    'nested repeater row key' => ['items.0.parts.3.serial', '', Reference::ELEMENT, $items, $serial],
    'nested repeater row' => ['items.0.parts.3', '', Reference::ELEMENT, $items, null],
    'nested repeater non-index' => ['items.0.parts.x', '', Reference::ELEMENT, $items, null],
    'key of an unknown scope' => ['name', '/unknown', Reference::UNKNOWN, null, null],
]);

test('Reference helpers', function (): void {
    expect(Reference::unknown()->kind)->toBe(Reference::UNKNOWN)
        ->and(Reference::unknown()->isKnown())->toBeFalse()
        ->and(Reference::unknown()->element)->toBeNull()
        ->and(Reference::unknown()->target)->toBeNull()
        ->and((new Reference(Reference::BUILTIN))->isKnown())->toBeTrue()
        ->and((new Reference(Reference::SCOPE))->element)->toBeNull();
});

test('LogicReferences::paths extracts static var paths outside iteration bodies', function (mixed $logic, array $expected): void {
    expect(LogicReferences::paths(fs_tree($logic)))->toBe($expected);
})->with([
    'scalar' => [5, []],
    'null' => [null, []],
    'string' => ['a', []],
    'plain var' => [['var' => 'a.b'], ['a.b']],
    'var with fallback' => [['var' => ['a', 0]], ['a']],
    'var with fallback var' => [['var' => ['a', ['var' => 'b']]], ['a', 'b']],
    'empty var' => [['var' => ''], ['']],
    'null var' => [['var' => null], ['']],
    'var without arguments' => [['var' => []], ['']],
    'numeric var' => [['var' => 1], ['1']],
    'fractional numeric var' => [['var' => 1.5], ['1.5']],
    'boolean var' => [['var' => true], []],
    'computed var' => [['var' => ['cat' => ['a', ['var' => 'b']]]], ['b']],
    'nested operators' => [['and' => [['var' => 'a'], ['!' => ['var' => 'b']]]], ['a', 'b']],
    'array of expressions' => [[['var' => 'a'], [['var' => 'b']]], ['a', 'b']],
    'empty array' => [[], []],
    'multi-key object is not an operation' => [['a' => 1, 'b' => 2], []],
    'empty object is not an operation' => [new stdClass, []],
    'map body excluded' => [['map' => [['var' => 'items'], ['var' => 'qty']]], ['items']],
    'filter body excluded' => [['filter' => [['var' => 'items'], ['==' => [['var' => 'done'], true]]]], ['items']],
    'some/all/none bodies excluded' => [['and' => [
        ['some' => [['var' => 'a'], ['var' => 'x']]],
        ['all' => [['var' => 'b'], ['var' => 'y']]],
        ['none' => [['var' => 'c'], ['var' => 'z']]],
    ]], ['a', 'b', 'c']],
    'reduce initial included' => [['reduce' => [['var' => 'items'], ['+' => [['var' => 'current'], ['var' => 'accumulator']]], ['var' => 'start']]], ['items', 'start']],
    'reduce without initial' => [['reduce' => [['var' => 'items'], ['var' => 'current']]], ['items']],
    'iteration without arguments' => [['map' => []], []],
    'non-list argument is wrapped' => [['!' => ['var' => 'a']], ['a']],
]);

test('LogicReferences::scopedArguments keeps non-iteration arguments', function (): void {
    expect(LogicReferences::scopedArguments('cat', ['a', 'b', 'c']))->toBe(['a', 'b', 'c'])
        ->and(LogicReferences::scopedArguments('map', ['a', 'b', 'c']))->toBe(['a'])
        ->and(LogicReferences::scopedArguments('reduce', ['a', 'b', 'c']))->toBe(['a', 'c'])
        ->and(LogicReferences::scopedArguments('reduce', ['a', 'b']))->toBe(['a'])
        ->and(LogicReferences::scopedArguments('some', []))->toBe([null]);
});

test('LogicReferences::staticPath', function (mixed $path, ?string $expected): void {
    expect(LogicReferences::staticPath($path))->toBe($expected);
})->with([
    'null' => [null, ''],
    'string' => ['a.b', 'a.b'],
    'integer' => [3, '3'],
    'integral float' => [2.0, '2'],
    'float' => [0.5, '0.5'],
    'boolean' => [false, null],
    'array' => [['a'], null],
    'object' => [new stdClass, null],
]);
