<?php

declare(strict_types=1);

use Tests\Support\Arch\DomainImports;

/*
 * Import baseline of the domain modules (plan KTD13): a module may import the other modules and the
 * App\Http / App\Filament layers it imports today, and nothing new. A baseline entry that no import backs
 * any more fails too, so the baseline can only shrink. Token scan instead of `arch()`: see DomainImports.
 */

/** @return array<string, array{modules?: list<string>, 'App\Http'?: list<string>, 'App\Filament'?: list<string>}> */
function domainImportBaseline(): array
{
    return require __DIR__.'/domain-import-baseline.php';
}

function domainImports(): DomainImports
{
    return new DomainImports(dirname(__DIR__, 2).'/app');
}

/** The files of $module that import $target (a module name or a layer), with the imported names. */
function importersOf(string $module, string $target): string
{
    $lines = [];
    foreach (domainImports()->importsByFile($module) as $file => $names) {
        foreach ($names as $name) {
            if (domainImports()->target($name, $module) === $target) {
                $lines[] = "{$file} imports {$name}";
            }
        }
    }

    return implode('; ', array_unique($lines));
}

it('lists exactly the modules under app/Domain', function (): void {
    $baseline = array_keys(domainImportBaseline());
    sort($baseline);

    expect($baseline)->toBe(domainImports()->modules());
});

it('imports no module or layer outside the baseline', function (): void {
    $baseline = domainImportBaseline();
    $violations = [];
    foreach (domainImports()->scan() as $module => $found) {
        foreach (array_diff($found['modules'], $baseline[$module]['modules'] ?? []) as $other) {
            $violations[] = "{$module} may not import module {$other}: ".importersOf($module, $other);
        }
        foreach ($found['files'] as $layer => $files) {
            foreach (array_diff($files, $baseline[$module][$layer] ?? []) as $file) {
                $violations[] = "{$file} may not import {$layer}: ".importersOf($module, $layer);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps no baseline entry without a matching import', function (): void {
    $baseline = domainImportBaseline();
    $stale = [];
    foreach (domainImports()->scan() as $module => $found) {
        foreach (array_diff($baseline[$module]['modules'] ?? [], $found['modules']) as $other) {
            $stale[] = "stale baseline entry: {$module} no longer imports module {$other}";
        }
        foreach (DomainImports::LAYERS as $layer) {
            foreach (array_diff($baseline[$module][$layer] ?? [], $found['files'][$layer] ?? []) as $file) {
                $stale[] = "stale baseline entry: {$file} no longer imports {$layer}";
            }
        }
    }

    expect($stale)->toBe([]);
});
