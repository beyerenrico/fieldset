<?php

declare(strict_types=1);

use App\Domain\Forms\Schema\DefinitionDiff;
use App\Domain\Forms\Schema\DiffEntry;
use FormSchema\Json\Json;

function diffFixture(array $elements): stdClass
{
    return Json::decode(json_encode(['specVersion' => '1.0.0', 'key' => 'k', 'title' => 'T', 'locale' => 'de-DE',
        'pages' => [['key' => 'p', 'title' => 'P', 'elements' => $elements]]]));
}

it('reports added, removed, changed and moved elements by id', function (): void {
    $a = ['id' => '1', 'type' => 'text', 'key' => 'a', 'label' => 'A'];
    $b = ['id' => '2', 'type' => 'text', 'key' => 'b', 'label' => 'B'];
    $c = ['id' => '3', 'type' => 'number', 'key' => 'c', 'label' => 'C'];

    $from = diffFixture([$a, $b, $c]);
    $to = diffFixture([$b, ['id' => '1', 'type' => 'text', 'key' => 'a', 'label' => 'A renamed', 'required' => true],
        ['id' => '4', 'type' => 'date', 'key' => 'd', 'label' => 'D']]);

    $entries = collect(DefinitionDiff::compare($from, $to))->keyBy('id');

    expect($entries['1']->change)->toBe(DiffEntry::CHANGED)
        ->and($entries['1']->properties)->toBe(['label', 'required'])
        ->and($entries['2']->change)->toBe(DiffEntry::MOVED)
        ->and($entries['3']->change)->toBe(DiffEntry::REMOVED)
        ->and($entries['4']->change)->toBe(DiffEntry::ADDED);
});

it('reports nothing for identical definitions', function (): void {
    $definition = diffFixture([['id' => '1', 'type' => 'text', 'key' => 'a', 'label' => 'A']]);

    expect(DefinitionDiff::compare($definition, $definition))->toBe([]);
});
