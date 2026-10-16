<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use Tests\Feature\Reports\ReportsScenario;

/* M5-T01: explorer columns = union of the keys of all versions (BUILD_PROMPT §8.4 AC: three versions). */

it('unions the keys of all versions with labels from the newest version that has the key', function (): void {
    $s = ReportsScenario::make();
    $catalog = app(TenantContext::class)->run($s->org, fn (): FieldCatalog => FieldCatalog::forForm($s->form));

    $columns = array_map(fn (ReportField $f): array => [$f->key, $f->label, $f->type], $catalog->topLevel());

    expect($columns)->toBe([
        ['customer', 'Client name', 'text'],   // renamed twice: newest label
        ['amount', 'Amount', 'money'],
        ['kind', 'Kind', 'select'],
        ['visit_date', 'Visit date', 'date'],  // added in v2
        ['items', 'Items', 'repeater'],        // inside a section: no key prefix
        ['done', 'Done', 'checkbox'],          // added in v3
        ['notes', 'Notes', 'textarea'],        // removed in v3: kept, after the newest version's keys
    ]);
});

it('knows repeater children, numeric keys and the field-value filters per type', function (): void {
    $s = ReportsScenario::make();
    $catalog = app(TenantContext::class)->run($s->org, fn (): FieldCatalog => FieldCatalog::forForm($s->form));

    expect(array_map(fn (ReportField $f): string => $f->key, $catalog->children('items')))->toBe(['items.description', 'items.qty', 'items.price'])
        ->and(array_map(fn (ReportField $f): string => $f->key, $catalog->numeric()))->toBe(['amount', 'items.qty', 'items.price'])
        ->and(array_map(fn (ReportField $f): array => [$f->key, $f->filterKind()], $catalog->filterable()))->toBe([
            ['amount', 'number'], ['kind', 'select'], ['visit_date', 'date'], ['done', 'boolean'],
        ])
        ->and($catalog->get('kind')?->options())->toBe(['repair' => 'Repair', 'maintenance' => 'Maintenance'])
        ->and($catalog->get('items.qty')?->repeater)->toBe('items');
});
