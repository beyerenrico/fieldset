<?php

declare(strict_types=1);

use App\Domain\Forms\Schema\DefinitionDiff;
use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Forms\Schema\DiffEntry;
use App\Domain\Forms\Schema\KeyRewriter;
use FormSchema\Json\Json;

function nestedDefinition(): stdClass
{
    return Json::decode(json_encode(['specVersion' => '1.0.0', 'key' => 'k', 'title' => 'T', 'locale' => 'de-DE', 'pages' => [[
        'id' => 'p1', 'key' => 'p', 'title' => 'P', 'elements' => [
            ['id' => 'c', 'type' => 'columns', 'key' => 'cols', 'columns' => [
                ['elements' => [['id' => 'a', 'type' => 'text', 'key' => 'a', 'label' => 'A']]],
                ['elements' => [['id' => 'r', 'type' => 'repeater', 'key' => 'rows', 'label' => 'R', 'elements' => [
                    ['id' => 'b', 'type' => 'number', 'key' => 'b', 'label' => 'B'],
                ]]]],
            ]],
        ]]]]));
}

it('walks elements in nested columns and repeaters in document order', function (): void {
    expect(array_map(fn ($e) => $e->id, DefinitionTools::elements(nestedDefinition())))->toBe(['c', 'a', 'r', 'b']);
});

it('regenerates every id without touching the input', function (): void {
    $original = nestedDefinition();
    $n = 0;
    $copy = DefinitionTools::regenerateIds($original, function () use (&$n): string {
        return 'id-'.++$n;
    });

    expect(array_map(fn ($e) => $e->id, DefinitionTools::elements($copy)))->toBe(['id-2', 'id-3', 'id-4', 'id-5'])
        ->and($copy->pages[0]->id)->toBe('id-1')
        ->and($original->pages[0]->elements[0]->id)->toBe('c');
});

it('reports page and settings changes and ignores page key renames for element positions', function (): void {
    $from = nestedDefinition();
    $to = DefinitionTools::copy($from);
    $to->pages[0]->key = 'renamed';
    $to->settings = (object) ['layout' => 'wizard'];
    $to->pages[] = (object) ['id' => 'p2', 'key' => 'q', 'title' => 'Q', 'elements' => []];

    $entries = collect(DefinitionDiff::compare($from, $to))->map(fn (DiffEntry $e) => [$e->change, $e->type, $e->id])->all();

    expect($entries)->toBe([
        [DiffEntry::CHANGED, 'form', 'definition'],
        [DiffEntry::CHANGED, 'page', 'p1'],
        [DiffEntry::ADDED, 'page', 'p2'],
    ]);
});

it('rewrites var paths, $root paths, fallbacks and row keys', function (): void {
    $logic = Json::decode('{"and":[{"var":"qty"},{"var":["qty.x",{"var":"price"}]},{"var":"$root.qty"},{"sum":[{"var":"rows"},"qty"]}]}');

    $out = KeyRewriter::logic($logic, ['qty' => 'qty2', 'price' => 'price2', 'rows' => 'rows2']);

    expect(Json::encode($out))->toBe('{"and":[{"var":"qty2"},{"var":["qty2.x",{"var":"price2"}]},{"var":"$root.qty2"},{"sum":[{"var":"rows2"},"qty2"]}]}');
});
