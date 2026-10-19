<?php

declare(strict_types=1);

/*
 * Upgrade migrations never use `CONCURRENTLY` (plan U18, ADR 0086): the upgrade child migrates with every writer
 * stopped, so a concurrent build buys nothing, and one interrupted by a timeout or a rollback leaves an invalid index
 * behind. The rule covers every migration after the 1.0.0 baseline (its last migration below): those run in an upgrade
 * child, while the four 1.0 index migrations that build concurrently (through ConcurrentIndex) already ran on every
 * database an fsa instance can hold.
 */

const UPGRADE_LINT_BASELINE = '2026_10_23_100000_add_submissions_org_updated_at_index.php';

/** Whether a migration's source builds or drops something concurrently. */
function upgradeLintConcurrent(string $source): bool
{
    return preg_match('/\bCONCURRENTLY\b|ConcurrentIndex::/i', $source) === 1;
}

it('finds no concurrent index build or drop in a migration after the 1.0.0 baseline', function (): void {
    $files = array_values(array_filter(array_map(basename(...), glob(base_path('database/migrations/*.php')) ?: []), static fn (string $name): bool => strcmp($name, UPGRADE_LINT_BASELINE) > 0));
    $offending = array_values(array_filter($files, static fn (string $name): bool => upgradeLintConcurrent((string) file_get_contents(base_path('database/migrations/'.$name)))));

    expect($files)->not->toBeEmpty()
        ->and(is_file(base_path('database/migrations/'.UPGRADE_LINT_BASELINE)))->toBeTrue()
        ->and($offending)->toBe([]);
});

it('recognises a concurrent build, a concurrent drop and the ConcurrentIndex helper', function (string $source, bool $concurrent): void {
    expect(upgradeLintConcurrent($source))->toBe($concurrent);
})->with([
    ["DB::statement('CREATE INDEX CONCURRENTLY x ON t (c)');", true],
    ["DB::statement('drop index concurrently if exists x');", true],
    ["ConcurrentIndex::create('x', 'ON t (c)');", true],
    ["Schema::table('t', fn (\$table) => \$table->index('c'));", false],
]);
