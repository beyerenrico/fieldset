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
    expect(array_map(fn (stdClass $e) => $e->id, DefinitionTools::elements(nestedDefinition())))->toBe(['c', 'a', 'r', 'b']);
});

it('regenerates every id without touching the input', function (): void {
    $original = nestedDefinition();
    $n = 0;
    $copy = DefinitionTools::regenerateIds($original, function () use (&$n): string {
        return 'id-'.++$n;
    });

    expect(array_map(fn (stdClass $e) => $e->id, DefinitionTools::elements($copy)))->toBe(['id-2', 'id-3', 'id-4', 'id-5'])
        ->and($copy->pages[0]->id)->toBe('id-1')
        ->and($original->pages[0]->elements[0]->id)->toBe('c');
});

it('reports page and settings changes and ignores page key renames for element positions', function (): void {
    $from = nestedDefinition();
    $to = DefinitionTools::copy($from);
    $to->pages[0]->key = 'renamed';
    $to->settings = (object) ['layout' => 'wizard'];
    $to->pages[] = (object) ['id' => 'p2', 'key' => 'q', 'title' => 'Q', 'elements' => []];

    $entries = collect(DefinitionDiff::compare($from, $to))->map(fn (DiffEntry $e): array => [$e->change, $e->type, $e->id])->all();

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

it('reports moved and removed pages and skips malformed nodes', function (): void {
    $from = nestedDefinition();
    $from->pages[] = (object) ['id' => 'p2', 'key' => 'q', 'title' => 'Q', 'elements' => ['not an element', (object) ['type' => 'text']]];
    $from->pages[] = (object) ['id' => 'p3', 'key' => 'r', 'title' => 'R', 'elements' => []];
    $to = DefinitionTools::copy($from);
    $to->pages = [$to->pages[1], $to->pages[0]]; // swap p1/p2, drop p3

    $entries = collect(DefinitionDiff::compare($from, $to))->map(fn (DiffEntry $e): array => [$e->change, $e->type, $e->id])->all();

    expect($entries)->toContain([DiffEntry::MOVED, 'page', 'p2'], [DiffEntry::MOVED, 'page', 'p1'], [DiffEntry::REMOVED, 'page', 'p3'])
        ->and(DefinitionTools::elements($from))->toHaveCount(5); // malformed entries are skipped, the typed stub is kept
});

it('leaves scalars untouched when regenerating ids', function (): void {
    $definition = (object) ['pages' => ['scalar', (object) ['id' => 'x', 'key' => 'p', 'elements' => []]]];

    $copy = DefinitionTools::regenerateIds($definition, fn (): string => 'new');

    expect($copy->pages[0])->toBe('scalar')->and($copy->pages[1]->id)->toBe('new');
});

it('rewrites signerNameFrom, ignores empty rename maps and non-path var arguments', function (): void {
    $element = (object) ['signerNameFrom' => 'customer', 'visibleIf' => (object) ['var' => 1]];
    KeyRewriter::element($element, []);
    expect($element->signerNameFrom)->toBe('customer');

    KeyRewriter::element($element, ['customer' => 'customer_copy']);
    expect($element->signerNameFrom)->toBe('customer_copy')->and(Json::encode($element->visibleIf))->toBe('{"var":1}');
});

it('finds the key of a role in the top-level scope through sections and columns, never inside repeaters', function (): void {
    $definition = Json::decode(json_encode(['pages' => [
        ['elements' => [
            ['type' => 'repeater', 'key' => 'contacts', 'elements' => [['type' => 'email', 'key' => 'row_email', 'role' => 'customer.email']]],
        ]],
        ['elements' => [
            ['type' => 'section', 'key' => 's', 'elements' => [
                ['type' => 'columns', 'key' => 'c', 'columns' => [
                    ['elements' => [['type' => 'text', 'key' => 'name', 'role' => 'customer.name']]],
                    ['elements' => [['type' => 'email', 'key' => 'mail', 'role' => 'customer.email']]],
                ]],
            ]],
            ['type' => 'email', 'key' => 'second_mail', 'role' => 'customer.email'],
        ]],
    ]]));

    expect(DefinitionTools::keyForRole($definition, 'customer.email'))->toBe('mail')
        ->and(DefinitionTools::keyForRole($definition, 'customer.name'))->toBe('name')
        ->and(DefinitionTools::keyForRole($definition, 'site.address'))->toBeNull()
        ->and(DefinitionTools::keyForRole(new stdClass, 'customer.email'))->toBeNull();
});
