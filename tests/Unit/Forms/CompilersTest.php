<?php

declare(strict_types=1);

use App\Domain\Forms\Builder\CalculationCompiler;
use App\Domain\Forms\Builder\LogicCompiler;
use FormSchema\Json\Json;

dataset('logic round trips', [
    'equals text' => ['{"==":[{"var":"type"},"repair"]}', 'rules'],
    'decimal compare' => ['{"dec.gt":[{"var":"qty"},"5"]}', 'rules'],
    'and group' => ['{"and":[{"!!":{"var":"a"}},{"in":["x",{"var":"tags"}]}]}', 'rules'],
    'not equal number' => ['{"!":{"dec.eq":[{"var":"qty"},"1"]}}', 'rules'],
    'date compare' => ['{"<":[{"var":"day"},"2026-12-31"]}', 'rules'],
    'unsupported shape' => ['{"some":[{"var":"rows"},{"var":"ok"}]}', 'json'],
    'boolean literal' => ['true', 'json'],
    'checkbox equals true' => ['{"===":[{"var":"ok"},true]}', 'rules'],
    'groups of groups' => ['{"or":[{"and":[{"!!":{"var":"a"}},{"dec.lt":[{"var":"qty"},"3"]}]},{"==":[{"var":"type"},"repair"]}]}', 'rules'],
]);

it('decompiles and recompiles logic without loss', function (string $json, string $mode): void {
    $compiler = new LogicCompiler(['qty' => 'number', 'type' => 'select', 'day' => 'date', 'tags' => 'multiselect', 'a' => 'text', 'ok' => 'checkbox']);
    $expression = Json::decode($json);
    $ui = $compiler->toUi($expression);

    expect($ui['mode'])->toBe($mode)
        ->and(Json::canonical($compiler->fromUi($ui)))->toBe(Json::canonical($expression));
})->with('logic round trips');

dataset('calc round trips', [
    'sum' => ['{"sum":[{"var":"items"},"line_total"]}', 'sum'],
    'product' => ['{"dec.mul":[{"var":"qty"},{"var":"unit_price"}]}', 'product'],
    'subtract' => ['{"dec.sub":[{"var":"a"},{"var":"b"}]}', 'subtract'],
    'percent' => ['{"dec.round":[{"dec.mul":[{"var":"net"},"0.19"]},2]}', 'percent'],
    'custom' => ['{"dec.div":[{"var":"a"},"3",4]}', 'json'],
]);

it('decompiles and recompiles calculations without loss', function (string $json, string $mode): void {
    $compiler = new CalculationCompiler;
    $expression = Json::decode($json);
    $ui = $compiler->toUi($expression);

    expect($ui['mode'])->toBe($mode)
        ->and(Json::canonical($compiler->fromUi($ui)))->toBe(Json::canonical($expression));
})->with('calc round trips');

it('compiles checkbox equality to a boolean comparison', function (): void {
    $compiler = new LogicCompiler(['ok' => 'checkbox']);
    $ui = ['mode' => 'rules', 'match' => 'all', 'groups' => [['match' => 'all', 'rules' => [['field' => 'ok', 'operator' => 'eq', 'value' => 'true']]]], 'json' => ''];

    expect(Json::encode($compiler->fromUi($ui)))->toBe('{"===":[{"var":"ok"},true]}');
});
