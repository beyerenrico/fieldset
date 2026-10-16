<?php

declare(strict_types=1);

use App\Domain\Forms\Import\Mapping\CalculationMapper;
use App\Domain\Forms\Import\Qpdf\ContentStreamText;
use FormSchema\Json\Json;

beforeEach(function (): void {
    $this->refs = [
        'A' => ['kind' => 'scalar', 'key' => 'a'],
        'B' => ['kind' => 'scalar', 'key' => 'b'],
        'grp.one' => ['kind' => 'scalar', 'key' => 'one'],
        'grp.two' => ['kind' => 'scalar', 'key' => 'two'],
        'Row1Qty' => ['kind' => 'cell', 'repeater' => 'rows', 'key' => 'qty', 'index' => 1, 'rows' => 2],
        'Row2Qty' => ['kind' => 'cell', 'repeater' => 'rows', 'key' => 'qty', 'index' => 2, 'rows' => 2],
        'Row1Price' => ['kind' => 'cell', 'repeater' => 'rows', 'key' => 'price', 'index' => 1, 'rows' => 2],
        'Row2Price' => ['kind' => 'cell', 'repeater' => 'rows', 'key' => 'price', 'index' => 2, 'rows' => 2],
    ];
    $this->compile = function (string $function, array $fields, ?array $row = null, int $scale = 2): array|string {
        $result = (new CalculationMapper)->compile(['function' => $function, 'fields' => $fields], $this->refs, $scale, $row);

        return is_string($result) ? $result : ['expression' => Json::canonical($result['expression']), 'warnings' => array_column($result['warnings'], 'code')];
    };
});

it('compiles top-level calculations', function (string $function, array $fields, string $expected): void {
    expect(($this->compile)($function, $fields)['expression'])->toBe($expected);
})->with([
    'sum of scalars' => ['SUM', ['A', 'B'], '{"sum":[[{"var":"a"},{"var":"b"}]]}'],
    'sum of a column' => ['SUM', ['Row1Qty', 'Row2Qty'], '{"sum":[{"var":"rows"},"qty"]}'],
    'sum of a column and a scalar' => ['SUM', ['Row1Qty', 'Row2Qty', 'A'], '{"sum":[[{"var":"a"},{"sum":[{"var":"rows"},"qty"]}]]}'],
    'product' => ['PRD', ['A', 'B'], '{"dec.mul":[{"var":["a","0"]},{"var":["b","0"]}]}'],
    'product of one' => ['PRD', ['A'], '{"dec.mul":[{"var":["a","0"]},1]}'],
    'average of scalars' => ['AVG', ['A', 'B'], '{"dec.div":[{"sum":[[{"var":"a"},{"var":"b"}]]},2,2]}'],
    'average of a column' => ['AVG', ['Row1Price', 'Row2Price'], '{"dec.div":[{"sum":[{"var":"rows"},"price"]},{"count":[{"var":"rows"}]},2]}'],
    'average mixed' => ['AVG', ['A', 'Row1Price', 'Row2Price'], '{"dec.div":[{"sum":[[{"var":"a"},{"sum":[{"var":"rows"},"price"]}]]},{"dec.add":[1,{"count":[{"var":"rows"}]}]},2]}'],
    'min of scalars' => ['MIN', ['A', 'B'], '{"min":[{"var":["a","0"]},{"var":["b","0"]}]}'],
    'max of one scalar' => ['MAX', ['A'], '{"var":["a","0"]}'],
    'max of a column and a scalar' => ['MAX', ['Row1Qty', 'Row2Qty', 'B'], '{"max":[{"var":["b","0"]},{"max":[{"var":"rows"},"qty"]}]}'],
    'parent name' => ['SUM', ['grp'], '{"sum":[[{"var":"one"},{"var":"two"}]]}'],
]);

it('compiles a row calculation with row variables and $root for top-level fields', function (): void {
    expect(($this->compile)('PRD', ['Row1Qty', 'Row1Price', 'A'], ['repeater' => 'rows', 'index' => 1])['expression'])
        ->toBe('{"dec.mul":[{"var":["qty","0"]},{"var":["price","0"]},{"var":["$root.a","0"]}]}');
});

it('reports what cannot be expressed', function (): void {
    expect(($this->compile)('SUM', ['Missing']))->toBe('calculation_reference')
        ->and(($this->compile)('PRD', ['Row1Qty', 'Row2Qty']))->toBe('unsupported_calculation')
        ->and(($this->compile)('SUM', ['Row2Qty'], ['repeater' => 'rows', 'index' => 1]))->toBe('unsupported_calculation');
});

it('warns when a calculation covers only part of a column', function (): void {
    expect(($this->compile)('SUM', ['Row1Qty']))->toBe(['expression' => '{"sum":[{"var":"rows"},"qty"]}', 'warnings' => ['partial_column']]);
});

it('extracts positioned text from content streams', function (): void {
    $stream = implode("\n", [
        'q 1 0 0 1 10 20 cm BT /F1 12 Tf 100 700 Td (Customer:) Tj ET Q',
        'BT /F1 10 Tf 1 0 0 1 50 600 Tm [(Unit) -300 (price)] TJ 14 TL T* (next \\(line\\)) Tj ET',
        'BT /F2 10 Tf 50 500 Td <00410042> Tj ET',
        'BT /F1 10 Tf 50 400 Td (Gr\\366\\337e) Tj ET',
        'BI /W 1 /H 1 ID xyz EI',
        '% (comment) Tj',
    ]);

    $runs = ContentStreamText::extract($stream, 3, ['F2' => true]);

    expect(array_map(fn ($r) => [$r->text, $r->x, $r->y, $r->page], $runs))->toBe([
        ['Customer:', 110.0, 720.0, 3],
        ['Unit price', 50.0, 600.0, 3],
        ['next (line)', 50.0, 586.0, 3],
        ['Größe', 50.0, 400.0, 3],
    ])->and($runs[0]->size)->toEqual(12.0);
});
