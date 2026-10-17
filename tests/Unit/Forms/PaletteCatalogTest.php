<?php

declare(strict_types=1);

use App\Domain\Forms\Builder\ElementFields;
use App\Domain\Forms\Builder\PaletteCatalog;
use App\Domain\Forms\Support\BlankDefinition;
use App\Domain\Forms\Support\DefinitionChecker;
use FormSchema\Json\Json;
use FormSchema\Lint\LintFinding;

/* Builder v2 palette (ADR 0037): every element type once, grouped, with a valid default node. */

it('groups every element type exactly once in the documented order', function (): void {
    $palette = PaletteCatalog::groups();

    $types = array_merge(...array_map(fn (array $g): array => array_column($g['items'], 'type'), $palette));
    expect(array_column($palette, 'group'))->toBe(['input', 'capture', 'computed', 'structure', 'layout'])
        ->and($types)->toHaveCount(count(ElementFields::ALL_TYPES))
        ->and(array_values(array_diff(ElementFields::ALL_TYPES, $types)))->toBe([])
        ->and(array_column($palette[3]['items'], 'type'))->toBe(['section', 'repeater', 'columns']);
});

it('matches the shape of the contract fixture', function (): void {
    app()->setLocale('de');
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/builder-v2/contract/config.json')), true)['palette'];
    $palette = PaletteCatalog::groups();

    expect(array_keys($palette[0]))->toBe(array_keys($fixture[0]))
        ->and(array_keys($palette[0]['items'][0]))->toBe(array_keys($fixture[0]['items'][0]))
        ->and($palette[0]['label'])->toBe('Eingabe')
        ->and($palette[0]['items'][0])->toBe($fixture[0]['items'][0])
        ->and(collect($palette[3]['items'])->firstWhere('type', 'columns')['node'])->toBe(collect($fixture[1]['items'])->firstWhere('type', 'columns')['node'])
        ->and(collect($palette[3]['items'])->firstWhere('type', 'repeater')['node']['key'])->toBe('rows');
});

it('offers default nodes that make a valid definition without lint errors', function (): void {
    $definition = BlankDefinition::make('palette', 'Palette', 'de-DE');
    foreach (PaletteCatalog::groups() as $group) {
        foreach ($group['items'] as $item) {
            expect($item['icon'])->toStartWith('heroicon-o-')
                ->and($item['node'])->not->toHaveKey('id')
                ->and($item['node']['type'])->toBe($item['type']);
            $definition->pages[0]->elements[] = Json::decode(json_encode(['id' => BlankDefinition::id()] + $item['node']));
        }
    }

    $report = app(DefinitionChecker::class)->check($definition);

    expect($report->schemaErrors)->toBe([])
        ->and(array_map(fn (LintFinding $f): string => $f->code.' '.$f->path, $report->errors()))->toBe([]);
});
