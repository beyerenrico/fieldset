<?php

declare(strict_types=1);

use App\Domain\Forms\Builder\BlockCloner;
use App\Domain\Forms\Builder\BuilderMapper;
use FormSchema\Json\Json;

it('clones a section with fresh ids, suffixed keys and rewritten references', function (): void {
    $definition = Json::decode(json_encode(['specVersion' => '1.0.0', 'key' => 'k', 'title' => 'T', 'locale' => 'de-DE', 'pages' => [[
        'key' => 'p', 'title' => 'P', 'elements' => [[
            'id' => '00000000-0000-4000-8000-000000000001', 'type' => 'section', 'key' => 'box', 'elements' => [
                ['id' => '00000000-0000-4000-8000-000000000002', 'type' => 'number', 'key' => 'qty', 'label' => 'Q'],
                ['id' => '00000000-0000-4000-8000-000000000003', 'type' => 'money', 'key' => 'price', 'label' => 'P', 'currency' => 'EUR'],
                ['id' => '00000000-0000-4000-8000-000000000004', 'type' => 'calculated', 'key' => 'total', 'label' => 'T',
                    'expression' => ['dec.mul' => [['var' => 'qty'], ['var' => 'price']]], 'visibleIf' => ['!!' => ['var' => '$root.other']]],
            ]]],
    ]]]));
    $block = (new BuilderMapper)->toState($definition)['pages'][0]['elements'][0];

    $clone = BlockCloner::clone($block);
    $section = (new BuilderMapper)->toDefinition(['pages' => [['key' => 'p', 'title' => 'P', 'elements' => [$clone]]]])->pages[0]->elements[0];

    expect($section->key)->toBe('box_copy')
        ->and($section->id)->not->toBe('00000000-0000-4000-8000-000000000001')
        ->and(array_map(fn ($e) => $e->key, $section->elements))->toBe(['qty_copy', 'price_copy', 'total_copy'])
        ->and(Json::encode($section->elements[2]->expression))->toBe('{"dec.mul":[{"var":"qty_copy"},{"var":"price_copy"}]}')
        ->and(Json::encode($section->elements[2]->visibleIf))->toBe('{"!!":{"var":"$root.other"}}')
        ->and($clone['data']['key_locked'])->toBeFalse();
});

it('gives a second copy a numbered key instead of a duplicate', function (): void {
    $definition = Json::decode(json_encode(['specVersion' => '1.0.0', 'key' => 'k', 'title' => 'T', 'locale' => 'de-DE', 'pages' => [[
        'key' => 'p', 'title' => 'P', 'elements' => [['id' => '00000000-0000-4000-8000-000000000001', 'type' => 'text', 'key' => 'note', 'label' => 'N']],
    ]]]));
    $block = (new BuilderMapper)->toState($definition)['pages'][0]['elements'][0];

    $first = BlockCloner::clone($block, ['note']);
    $second = BlockCloner::clone($block, ['note', 'note_copy']);

    expect($first['data']['key'])->toBe('note_copy')->and($second['data']['key'])->toBe('note_copy2')
        ->and($second['data']['id'])->not->toBe($first['data']['id']);
});
