<?php

declare(strict_types=1);

use FormSchema\Definition\Definition;
use FormSchema\Definition\DependencyGraph;
use FormSchema\Definition\Element;
use FormSchema\Definition\StronglyConnected;

require_once __DIR__.'/../Support/helpers.php';

$calc = static fn (string $key, mixed $expression): array => fs_element('calculated', $key, ['label' => $key, 'expression' => $expression]);
$add = static fn (string ...$keys): array => ['dec.add' => array_map(static fn (string $k): array => ['var' => $k], $keys)];

$graph = static fn (array $elements): DependencyGraph => DependencyGraph::build(Definition::fromJson(fs_definition($elements)));
$keys = static fn (array $elements): array => array_map(static fn (Element $e): string => $e->key, $elements);

test('a definition without calculated elements has an empty graph', function () use ($graph): void {
    $built = $graph([fs_element('text', 'a', ['label' => 'A'])]);

    expect($built->nodes)->toBe([])
        ->and($built->edges)->toBe([])
        ->and($built->order())->toBe([])
        ->and($built->cycleHeads())->toBe([]);
});

test('nodes are calculated elements by path and edges list calculated dependencies in document order', function () use ($graph, $calc, $add): void {
    $built = $graph([
        fs_element('number', 'qty', ['label' => 'Qty']),
        $calc('c', $add('b', 'a', 'qty', 'unknown', '$today')),
        $calc('a', 1),
        $calc('b', ['var' => ['a']]),
    ]);

    expect(array_keys($built->nodes))->toBe(['/pages/0/elements/1', '/pages/0/elements/2', '/pages/0/elements/3'])
        ->and($built->edges)->toBe([
            '/pages/0/elements/1' => ['/pages/0/elements/2', '/pages/0/elements/3'],
            '/pages/0/elements/2' => [],
            '/pages/0/elements/3' => ['/pages/0/elements/2'],
        ]);
});

test('order puts dependencies first and keeps document order otherwise', function (array $elements, array $expected) use ($graph, $keys): void {
    expect($keys($graph($elements)->order()))->toBe($expected);
})->with([
    'independent' => fn () => [[$calc('x', 1), $calc('y', 2), $calc('z', 3)], ['x', 'y', 'z']],
    'chain defined backwards' => fn () => [[$calc('a', $add('b')), $calc('b', $add('c')), $calc('c', 1)], ['c', 'b', 'a']],
    'shared dependency' => fn () => [[$calc('a', $add('c')), $calc('b', $add('c')), $calc('c', 1)], ['c', 'a', 'b']],
    'diamond' => fn () => [[$calc('top', $add('l', 'r')), $calc('l', $add('base')), $calc('r', $add('base')), $calc('base', 1)], ['base', 'l', 'r', 'top']],
    'cycle members once each' => fn () => [[$calc('a', $add('b')), $calc('b', $add('a')), $calc('c', 1)], ['c', 'a', 'b']],
    'ready elements in document order' => fn () => [[$calc('a', 1), $calc('b', $add('d')), $calc('c', 1), $calc('d', 1)], ['a', 'c', 'd', 'b']],
    'row calculations before the aggregate' => fn () => [[
        $calc('total', ['sum' => [['var' => 'items'], 'line']]),
        fs_element('repeater', 'items', ['label' => 'Items', 'elements' => [
            fs_element('number', 'qty', ['label' => 'Qty']),
            $calc('line', $add('qty')),
            fs_element('repeater', 'parts', ['label' => 'Parts', 'elements' => [$calc('part', 1)]]),
        ]]),
    ], ['line', 'part', 'total']],
]);

test('a reference depends on the element of its first key', function () use ($graph, $calc): void {
    $built = $graph([
        $calc('first_line', ['var' => 'items.0.line']),
        fs_element('repeater', 'items', ['label' => 'Items', 'elements' => [$calc('line', 1), fs_element('number', 'qty', ['label' => 'Q'])]]),
        $calc('first_qty', ['var' => 'items.0.qty']),
        $calc('in_map', ['map' => [['var' => 'items'], ['var' => 'line']]]),
        $calc('via_root', ['cat' => [['var' => '$root.first_qty'], ['var' => ''], ['var' => '$today'], ['var' => ['cat' => ['in', '_map']]]]]),
        $calc('anything', ['var' => 'items.7.whatever']),
    ]);
    $line = ['/pages/0/elements/1/elements/0'];

    expect($built->edges['/pages/0/elements/0'])->toBe($line)
        ->and($built->edges['/pages/0/elements/2'])->toBe($line)
        ->and($built->edges['/pages/0/elements/3'])->toBe($line)
        ->and($built->edges['/pages/0/elements/4'])->toBe(['/pages/0/elements/2'])
        ->and($built->edges['/pages/0/elements/5'])->toBe($line)
        ->and($built->edges['/pages/0/elements/1/elements/0'])->toBe([]);
});

test('cycleHeads reports the first element of every cycle in document order', function (array $elements, array $expected) use ($graph, $keys): void {
    expect($keys($graph($elements)->cycleHeads()))->toBe($expected);
})->with([
    'no cycle' => fn () => [[$calc('a', $add('b')), $calc('b', 1)], []],
    'self loop' => fn () => [[$calc('x', 1), $calc('self', $add('self'))], ['self']],
    'two nodes' => fn () => [[$calc('a', $add('b')), $calc('b', $add('a'))], ['a']],
    'three nodes reached out of order' => fn () => [[$calc('a', $add('c')), $calc('b', $add('a')), $calc('c', $add('b'))], ['a']],
    'two independent cycles' => fn () => [[
        $calc('a', $add('b')), $calc('b', $add('a')),
        $calc('c', 1),
        $calc('d', $add('e')), $calc('e', $add('d')),
    ], ['a', 'd']],
    'later cycle found first' => fn () => [[
        $calc('p', $add('q', 'r')), $calc('q', $add('p')),
        $calc('r', $add('s')), $calc('s', $add('r')),
    ], ['p', 'r']],
    'dependency on a cycle is not a cycle' => fn () => [[$calc('a', $add('b')), $calc('b', $add('c')), $calc('c', $add('b'))], ['b']],
    'repeater aggregate cycle' => fn () => [[
        fs_element('repeater', 'items', ['label' => 'Items', 'elements' => [$calc('line', $add('total'))]]),
        $calc('total', ['sum' => [['var' => 'items'], 'line']]),
    ], ['line']],
]);

test('StronglyConnected lists components with members in input order', function (array $nodes, array $edges, array $expected): void {
    expect(StronglyConnected::components($nodes, $edges))->toBe($expected);
})->with([
    'empty' => [[], [], []],
    'isolated nodes' => [['a', 'b'], [], [['a'], ['b']]],
    'chain emits dependencies first' => [['a', 'b', 'c'], ['a' => ['b'], 'b' => ['c']], [['c'], ['b'], ['a']]],
    'cycle and a separate node' => [['a', 'b', 'c'], ['a' => ['b'], 'b' => ['a']], [['a', 'b'], ['c']]],
    'cycle reached from its last member' => [['a', 'b', 'c'], ['c' => ['b'], 'b' => ['a'], 'a' => ['c']], [['a', 'b', 'c']]],
    'cross edge to a finished component' => [['a', 'b', 'c'], ['a' => ['b'], 'c' => ['b']], [['b'], ['a'], ['c']]],
    'self loop' => [['a'], ['a' => ['a']], [['a']]],
    'node outside the list sorts last' => [['a'], ['a' => ['z'], 'z' => ['a']], [['a', 'z']]],
]);
