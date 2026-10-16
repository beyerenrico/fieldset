<?php

declare(strict_types=1);

use App\Domain\Forms\Import\Mapping\KeyAllocator;
use App\Domain\Forms\Import\Mapping\LabelResolver;
use App\Domain\Forms\Import\Mapping\MappedField;
use App\Domain\Forms\Import\Mapping\RowDetector;
use App\Domain\Forms\Import\Model\TextRun;
use Tests\Unit\Forms\PdfImportFixtures as Fx;

/** @param  list<array{0: string, 1: float, 2: float}>  $specs  name, x, y */
function pdfRows(array $specs): array
{
    return array_map(fn (array $s): MappedField => new MappedField(Fx::field($s[0], $s[1], $s[2]), 'text'), $specs);
}

it('groups Row{n}<Column> names into one table, columns left to right', function (): void {
    $fields = [];
    foreach ([1, 2, 3] as $row) {
        $fields[] = ['Row'.$row.'Qty', 300, 700 - $row * 25];
        $fields[] = ['Row'.$row.'Desc', 50, 700 - $row * 25];
    }
    [$tables, $rest] = (new RowDetector)->detect(pdfRows($fields));

    expect($tables)->toHaveCount(1)->and($rest)->toBe([])
        ->and(array_keys($tables[0]->columns))->toBe(['Desc', 'Qty'])
        ->and($tables[0]->indices)->toBe([1, 2, 3])
        ->and($tables[0]->stem)->toBe('Row')
        ->and($tables[0]->aligned)->toBeTrue();
});

it('accepts a single Row{n}Desc column and a single desc.{n} column', function (array $names): void {
    $fields = array_map(fn (string $n, int $i): array => [$n, 50, 700 - $i * 25], $names, array_keys($names));
    [$tables] = (new RowDetector)->detect(pdfRows($fields));

    expect($tables)->toHaveCount(1)->and(count($tables[0]->indices))->toBe(count($names));
})->with([
    'flat' => [['Row1Desc', 'Row2Desc', 'Row3Desc']],
    'hierarchical' => [['desc.0', 'desc.1', 'desc.2', 'desc.3']],
    'hierarchical children' => [['items.0.desc', 'items.1.desc']],
]);

it('does not treat generic numbered names as a table', function (array $specs): void {
    [$tables, $rest] = (new RowDetector)->detect(pdfRows($specs));

    expect($tables)->toBe([])->and($rest)->toHaveCount(count($specs));
})->with([
    'one generic column' => [[['Text1', 50, 700], ['Text2', 50, 670], ['Text3', 50, 640]]],
    'two columns that do not line up' => [[['Text1', 50, 700], ['Text2', 50, 670], ['Check1', 300, 500], ['Check2', 300, 300]]],
    'not consecutive' => [[['Row1Desc', 50, 700], ['Row3Desc', 50, 650]]],
    'a single row' => [[['Row1Desc', 50, 700], ['Row1Qty', 300, 700]]],
]);

it('accepts generic numbered names when two columns line up row by row', function (): void {
    [$tables] = (new RowDetector)->detect(pdfRows([
        ['Text1', 50, 700], ['Text2', 50, 670], ['Amount1', 300, 701], ['Amount2', 300, 669],
    ]));

    expect($tables)->toHaveCount(1)->and(array_keys($tables[0]->columns))->toBe(['Text', 'Amount']);
});

it('keeps stem tables whose rows do not line up, flagged as not aligned', function (): void {
    [$tables] = (new RowDetector)->detect(pdfRows([['Row1Desc', 50, 600], ['Row2Desc', 50, 700]]));

    expect($tables)->toHaveCount(1)->and($tables[0]->aligned)->toBeFalse();
});

it('makes keys snake_case and unique per scope', function (): void {
    $keys = new KeyAllocator;

    expect([$keys->allocate('CustomerName'), $keys->allocate('customer name'), $keys->allocate('Customer_Name')])
        ->toBe(['customer_name', 'customer_name_2', 'customer_name_3'])
        ->and(KeyAllocator::snake('Row1Desc'))->toBe('row1_desc')
        ->and(KeyAllocator::snake('ABCField'))->toBe('abc_field')
        ->and(KeyAllocator::snake('Größe (cm)'))->toBe('grosse_cm')
        ->and(KeyAllocator::snake('1st line'))->toBe('field_1st_line')
        ->and(KeyAllocator::snake('§$%'))->toBe('field')
        ->and(strlen(KeyAllocator::snake(str_repeat('a', 100))))->toBe(64)
        ->and((new KeyAllocator)->allocate('x'))->toBe('x');
});

it('humanizes field names', function (string $name, string $label): void {
    expect(LabelResolver::humanize($name))->toBe($label);
})->with([['CustomerName', 'Customer name'], ['order_no', 'Order no'], ['TechnicianID', 'Technician id'], ['', 'Field']]);

it('prefers tooltip, then the nearest text left or above, then the name', function (): void {
    $resolver = new LabelResolver;
    $field = Fx::field('ServiceDate', 160, 700);
    $left = new TextRun(1, 50, 706, 65, 10, 'Service date:');
    $above = new TextRun(1, 160, 726, 40, 10, 'Datum');
    $far = new TextRun(1, 160, 800, 40, 10, 'Heading');

    expect($resolver->resolve(Fx::field('A', tooltip: ' Tip  text '), [$left], 'A'))->toBe(['label' => 'Tip text', 'source' => 'tooltip'])
        ->and($resolver->resolve($field, [$left, $above, $far], 'ServiceDate'))->toBe(['label' => 'Datum', 'source' => 'text'])
        ->and($resolver->resolve($field, [$left, $far], 'ServiceDate'))->toBe(['label' => 'Service date', 'source' => 'text'])
        ->and($resolver->resolve($field, [$far], 'ServiceDate'))->toBe(['label' => 'Service date', 'source' => 'name'])
        ->and($resolver->resolve(Fx::field('A', tooltip: ' : '), [], 'OrderNo'))->toBe(['label' => 'Order no', 'source' => 'name']);
});
