<?php

declare(strict_types=1);

use App\Domain\Forms\Import\Mapping\UnmappedField;
use App\Domain\Forms\Support\DefinitionChecker;
use Tests\Unit\Forms\PdfImportFixtures as Fx;

/*
 * Mapping of the generated fixtures (scripts/fixtures/make-fillable-pdfs.mjs) from their stored qpdf
 * JSON: every row of the BUILD_PROMPT §8.13 table.
 */

beforeEach(function (): void {
    $this->result = Fx::build('service-sheet', 'Service sheet');
    $this->definition = $this->result->definition;
});

it('reads all fields of the service sheet without their values', function (): void {
    $document = Fx::document('service-sheet');

    expect($document->pageCount)->toBe(2)
        ->and($document->title)->toBe('Service sheet')
        ->and($document->fields)->toHaveCount(52)
        ->and(serialize($document))->not->toContain('Erika Mustermann')->not->toContain('T-0042')->not->toContain('secret-ref');
});

it('produces a definition that passes the meta-schema and the linter', function (string $fixture): void {
    $report = app(DefinitionChecker::class)->check(Fx::build($fixture)->definition);

    expect($report->schemaErrors)->toBe([])->and($report->findings)->toBe([]);
})->with(['service-sheet', 'hierarchical']);

it('creates one page per PDF page with fields, in reading order', function (): void {
    expect(array_map(fn ($p) => $p->key, $this->definition->pages))->toBe(['page_1', 'page_2'])
        ->and(array_map(fn ($e) => $e->key, $this->definition->pages[0]->elements))->toBe([
            'customer_name', 'service_date', 'phone', 'service_type', 'trades', 'payment_method',
            'work_completed', 'postcode', 'order_no', 'notes', 'technician_id',
        ])
        ->and(array_map(fn ($e) => $e->key, $this->definition->pages[1]->elements))->toBe([
            'rows', 'net_total', 'avg_price', 'max_qty', 'travel', 'grand_total', 'customer_signature',
        ]);
});

it('maps text fields by Acrobat formatting, flags and limits', function (): void {
    $d = $this->definition;

    expect(Fx::element($d, 'customer_name'))->type->toBe('text')->label->toBe('Customer')->required->toBeTrue()
        ->and(Fx::element($d, 'service_date')->type)->toBe('date')
        ->and(Fx::element($d, 'phone')->type)->toBe('phone')
        ->and(Fx::element($d, 'notes')->type)->toBe('textarea')
        ->and(Fx::element($d, 'postcode')->validation->maxLength)->toBe(5)
        ->and(Fx::element($d, 'order_no')->validation->maxLength)->toBe(12)
        ->and(Fx::element($d, 'technician_id')->readOnlyIf)->toBeTrue()
        ->and(Fx::element($d, 'qty')->type)->toBe('number')
        ->and(Fx::element($d, 'price'))->type->toBe('money')->currency->toBe('EUR')->scale->toBe(2)
        ->and(Fx::element($d, 'travel')->type)->toBe('money');
});

it('maps buttons, choices and signatures with their options', function (): void {
    $d = $this->definition;

    expect(Fx::element($d, 'work_completed')->type)->toBe('checkbox')
        ->and(Fx::element($d, 'payment_method'))->type->toBe('radio')
        ->and(Fx::json(Fx::element($d, 'payment_method')->options))->toBe('[{"label":"Cash","value":"Cash"},{"label":"Invoice","value":"Invoice"}]')
        ->and(Fx::element($d, 'service_type'))->type->toBe('select')->required->toBeTrue()->label->toBe('Service type')
        ->and(array_map(fn ($o) => $o->value, Fx::element($d, 'service_type')->options))->toBe(['Maintenance', 'Repair', 'Installation'])
        ->and(Fx::element($d, 'trades')->type)->toBe('multiselect')
        ->and(Fx::json(Fx::element($d, 'trades')->options))->toBe('[{"label":"Electrical","value":"el"},{"label":"Plumbing","value":"pl"},{"label":"HVAC","value":"hv"}]')
        ->and(Fx::element($d, 'customer_signature')->type)->toBe('signature');
});

it('turns the 8-row materials table into one repeater without a row limit', function (): void {
    $rows = Fx::element($this->definition, 'rows');

    expect($rows->type)->toBe('repeater')
        ->and($rows->display)->toBe('table')
        ->and(property_exists($rows, 'maxItems'))->toBeFalse()
        ->and(property_exists($rows, 'minItems'))->toBeFalse()
        ->and($rows->label)->toBe('Materials')
        ->and(array_map(fn ($e): array => [$e->key, $e->type, $e->label], $rows->elements))->toBe([
            ['desc', 'text', 'Description'], ['qty', 'number', 'Qty'], ['price', 'money', 'Unit price'], ['total', 'calculated', 'Total'],
        ])
        ->and($rows->{'x-import'}->field)->toBe('Row{n}*');
});

it('compiles Acrobat built-in calculations into decimal JsonLogic', function (): void {
    $d = $this->definition;
    $expr = fn (string $key): string => Fx::json(Fx::element($d, $key)->expression);

    expect($expr('total'))->toBe('{"dec.mul":[{"var":["qty","0"]},{"var":["price","0"]}]}')
        ->and($expr('net_total'))->toBe('{"sum":[{"var":"rows"},"total"]}')
        ->and($expr('avg_price'))->toBe('{"dec.div":[{"sum":[{"var":"rows"},"price"]},{"count":[{"var":"rows"}]},2]}')
        ->and($expr('max_qty'))->toBe('{"max":[{"var":"rows"},"qty"]}')
        ->and($expr('grand_total'))->toBe('{"sum":[[{"var":"net_total"},{"var":"travel"}]]}')
        ->and(Fx::element($d, 'net_total'))->format->toBe('money')->currency->toBe('EUR')->scale->toBe(2)
        ->and(Fx::element($d, 'max_qty'))->format->toBe('number')->scale->toBe(2);
});

it('lists every field that was not imported, with page and reason', function (): void {
    expect(array_map(fn (UnmappedField $u): array => $u->toArray(), $this->result->report->unmapped))->toBe([
        ['field' => 'InternalRef', 'page' => 1, 'reason' => 'hidden'],
        ['field' => 'PrintButton', 'page' => 1, 'reason' => 'push_button'],
        ['field' => 'Discount', 'page' => 2, 'reason' => 'custom_calculation'],
    ])
        ->and($this->result->report->stats)->toMatchArray([
            'pages' => 2, 'fields' => 52, 'imported' => 49, 'unmapped' => 3, 'repeaters' => 1, 'calculated' => 5, 'low_confidence' => 1,
        ]);
});

it('never imports values filled into the PDF', function (): void {
    $json = Fx::json($this->definition);

    foreach (['Erika Mustermann', 'T-0042', 'secret-ref', 'Pump seal', '12,50', '16.10.2026', '0301234567', 'noisy pump'] as $value) {
        expect($json)->not->toContain($value);
    }
    foreach (Fx::elements($this->definition) as $element) {
        expect(property_exists($element, 'default'))->toBeFalse();
    }
});

it('records provenance on every element', function (): void {
    foreach (Fx::elements($this->definition) as $element) {
        $import = $element->{'x-import'};
        expect($import->source)->toBe('acroform')
            ->and($import->page)->toBeInt()->toBeGreaterThan(0)
            ->and($import->rect)->toHaveCount(4)
            ->and($import->confidence)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1)
            ->and($element->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    }

    expect(Fx::element($this->definition, 'customer_name')->{'x-import'})
        ->field->toBe('CustomerName')->rect->toBe([159.5, 739.5, 460.5, 760.5])->confidence->toEqual(1.0);
});

it('takes labels from tooltips, then nearby text, then the field name, and scores confidence', function (): void {
    $d = $this->definition;

    expect(Fx::element($d, 'service_date'))->label->toBe('Service date')
        ->and(Fx::element($d, 'service_date')->{'x-import'}->confidence)->toEqual(0.8)
        ->and(Fx::element($d, 'postcode')->label)->toBe('Postcode')
        ->and(Fx::element($d, 'postcode')->{'x-import'}->confidence)->toBeLessThan(0.6)
        ->and(Fx::element($d, 'desc')->label)->toBe('Description');
});

it('detects hierarchical and single-column numbered names as repeaters', function (): void {
    $d = Fx::build('hierarchical')->definition;
    $items = Fx::element($d, 'items');
    $notes = Fx::element($d, 'rows');

    expect(array_map(fn ($e) => $e->key, $items->elements))->toBe(['desc', 'qty', 'price', 'total'])
        ->and(property_exists($items, 'maxItems'))->toBeFalse()
        ->and($items->{'x-import'}->field)->toBe('items.{n}.*')
        ->and(Fx::json(Fx::element($d, 'sum')->expression))->toBe('{"sum":[{"var":"items"},"total"]}')
        ->and(Fx::json(Fx::element($d, 'cheapest')->expression))->toBe('{"min":[{"var":"items"},"price"]}')
        ->and(array_map(fn ($e): array => [$e->key, $e->label], $notes->elements))->toBe([['notes', 'Remarks']])
        ->and($notes->label)->toBe('Remarks');
});
