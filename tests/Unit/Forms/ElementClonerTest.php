<?php

declare(strict_types=1);

use App\Domain\Forms\Builder\ElementCloner;
use App\Domain\Forms\Schema\DefinitionTools;
use FormSchema\Json\Json;

/* Builder v2 cloneNode (ADR 0037): fresh ids, keys unique in the target scope, references rewritten. */

function clonerSection(): stdClass
{
    return Json::decode(json_encode([
        'id' => '00000000-0000-4000-8000-000000000001', 'type' => 'section', 'key' => 'box', 'x-note' => ['keep' => true], 'elements' => [
            ['id' => '00000000-0000-4000-8000-000000000002', 'type' => 'number', 'key' => 'qty', 'label' => 'Q'],
            ['id' => '00000000-0000-4000-8000-000000000003', 'type' => 'columns', 'key' => 'cols', 'columns' => [
                ['id' => '00000000-0000-4000-8000-000000000004', 'elements' => [
                    ['id' => '00000000-0000-4000-8000-000000000005', 'type' => 'money', 'key' => 'price', 'label' => 'P', 'currency' => 'EUR'],
                ]],
                ['elements' => []],
            ]],
            ['id' => '00000000-0000-4000-8000-000000000006', 'type' => 'calculated', 'key' => 'total', 'label' => 'T',
                'expression' => ['dec.mul' => [['var' => 'qty'], ['var' => 'price']]], 'visibleIf' => ['!!' => ['var' => '$root.other']]],
            ['id' => '00000000-0000-4000-8000-000000000007', 'type' => 'repeater', 'key' => 'rows', 'label' => 'R', 'elements' => [
                ['id' => '00000000-0000-4000-8000-000000000008', 'type' => 'number', 'key' => 'qty', 'label' => 'Row qty'],
                ['id' => '00000000-0000-4000-8000-000000000009', 'type' => 'calculated', 'key' => 'line', 'label' => 'L',
                    'expression' => ['dec.mul' => [['var' => 'qty'], ['var' => '$root.price']]]],
            ]],
        ],
    ], JSON_UNESCAPED_SLASHES));
}

/** @return list<stdClass> the node and all descendants, in document order */
function clonerNodes(stdClass $node): array
{
    return DefinitionTools::elements((object) ['pages' => [(object) ['elements' => [$node]]]]);
}

it('gives the whole subtree fresh UUIDv7 ids, columns included', function (): void {
    $original = clonerSection();

    $clone = ElementCloner::clone($original, []);

    $ids = array_map(fn (stdClass $n): string => $n->id, clonerNodes($clone));
    $ids[] = $clone->elements[1]->columns[0]->id;
    expect($ids)->toHaveCount(9)->and(array_unique($ids))->toHaveCount(9)
        ->and(array_filter($ids, fn (string $id): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1))->toHaveCount(9)
        ->and(property_exists($clone->elements[1]->columns[1], 'id'))->toBeFalse()
        ->and(Json::canonical($original))->toBe(Json::canonical(clonerSection())); // input untouched
});

it('suffixes keys of the scope, rewrites references and keeps repeater rows as they are', function (): void {
    $clone = ElementCloner::clone(clonerSection(), ['box', 'qty', 'cols', 'price', 'total', 'rows', 'other']);

    $keys = array_map(fn (stdClass $n): string => $n->key, clonerNodes($clone));
    expect($keys)->toBe(['box_copy', 'qty_copy', 'cols_copy', 'price_copy', 'total_copy', 'rows_copy', 'qty', 'line'])
        ->and(Json::encode($clone->elements[2]->expression))->toBe('{"dec.mul":[{"var":"qty_copy"},{"var":"price_copy"}]}')
        ->and(Json::encode($clone->elements[2]->visibleIf))->toBe('{"!!":{"var":"$root.other"}}')
        ->and(Json::encode($clone->elements[3]->elements[1]->expression))->toBe('{"dec.mul":[{"var":"qty"},{"var":"$root.price_copy"}]}')
        ->and($clone->{'x-note'}->keep)->toBeTrue();
});

it('numbers further copies instead of repeating a key', function (): void {
    $text = Json::decode('{"id":"00000000-0000-4000-8000-000000000001","type":"text","key":"note","label":"N"}');

    $second = ElementCloner::clone($text, ['note', 'note_copy']);
    $third = ElementCloner::clone($second, ['note', 'note_copy', 'note_copy2']);

    expect($second->key)->toBe('note_copy2')->and($third->key)->toBe('note_copy3');
});

it('always suffixes the copy, even when the scope keys leave the original out', function (): void {
    $clone = ElementCloner::clone(clonerSection(), []);

    expect($clone->key)->toBe('box_copy')->and($clone->elements[1]->key)->toBe('cols_copy');
});

it('keeps suffixed keys within 64 characters', function (): void {
    $long = str_repeat('k', 64);
    $text = Json::decode('{"id":"00000000-0000-4000-8000-000000000001","type":"text","key":"'.$long.'","label":"N"}');

    $clone = ElementCloner::clone($text, [$long, substr($long, 0, 59).'_copy']);

    expect($clone->key)->toBe(substr($long, 0, 58).'_copy2')->and(strlen($clone->key))->toBe(64);
});

it('clones a page with a unique page key and suffixed element keys', function (): void {
    $page = Json::decode('{"id":"00000000-0000-4000-8000-0000000000a1","key":"start","title":"Start","elements":['
        .'{"id":"00000000-0000-4000-8000-000000000001","type":"text","key":"name","label":"N"},'
        .'{"id":"00000000-0000-4000-8000-000000000002","type":"text","key":"info","label":"I","visibleIf":{"!!":{"var":"name"}}}]}');

    $clone = ElementCloner::clone($page, ['start', 'name', 'info']);

    expect($clone->key)->toBe('start_copy')
        ->and($clone->id)->not->toBe('00000000-0000-4000-8000-0000000000a1')
        ->and(array_map(fn (stdClass $e): string => $e->key, $clone->elements))->toBe(['name_copy', 'info_copy'])
        ->and(Json::encode($clone->elements[1]->visibleIf))->toBe('{"!!":{"var":"name_copy"}}');
});
