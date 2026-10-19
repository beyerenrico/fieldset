<?php

declare(strict_types=1);

use App\Domain\Platform\Instance\Upgrade\VersionSteps;

/*
 * Upgrade migrations never use `CONCURRENTLY` (plan U18, ADR 0086): the upgrade child migrates with every writer
 * stopped, so a concurrent build buys nothing, and one interrupted by a timeout or a rollback leaves an invalid index
 * behind. The rule covers everything the child runs: every migration directory `migrate` uses (packages' own paths and
 * database/migrations, as UpgradeMigrator) and the version steps (VersionSteps::STEPS). The only exceptions are the four
 * 1.0 index migrations that build concurrently (through ConcurrentIndex), by exact file name: they shipped in 1.0.0 and
 * already ran on every database an fsa instance can hold. A date cutoff would let a new file with a backdated name
 * through (review A12).
 */

const UPGRADE_LINT_ALLOWED = [
    '2026_10_19_100050_add_dashboard_and_report_indexes.php',
    '2026_10_19_200004_add_submission_values_trigram_index.php',
    '2026_10_19_200005_add_explorer_sort_index.php',
    '2026_10_23_100000_add_submissions_org_updated_at_index.php',
];

/** The last migration of the 1.0.0 release. */
const UPGRADE_LINT_BASELINE = '2026_10_23_100000_add_submissions_org_updated_at_index.php';

/** Whether a source builds or drops something concurrently. */
function upgradeLintConcurrent(string $source): bool
{
    return preg_match('/\bCONCURRENTLY\b|ConcurrentIndex::/i', $source) === 1;
}

/** @return list<string> the migration directories the upgrade child runs, as UpgradeMigrator collects them */
function upgradeLintDirectories(): array
{
    return array_values([...app('migrator')->paths(), database_path('migrations')]);
}

/**
 * @param  list<string>  $directories
 * @param  list<class-string>  $steps
 * @return list<string> the files that build or drop concurrently, but for the allowed 1.0.0 migrations
 */
function upgradeLintOffending(array $directories, array $steps): array
{
    $allowed = array_map(static fn (string $name): string => (string) realpath(database_path('migrations/'.$name)), UPGRADE_LINT_ALLOWED);
    $files = [];
    foreach ($directories as $directory) {
        array_push($files, ...(glob(rtrim($directory, '/').'/*.php') ?: []));
    }
    $files = array_values(array_filter($files, static fn (string $file): bool => ! in_array(realpath($file), $allowed, true)));
    foreach ($steps as $class) {
        $files[] = (string) new ReflectionClass($class)->getFileName();
    }

    return array_values(array_filter($files, static fn (string $file): bool => upgradeLintConcurrent((string) file_get_contents($file))));
}

function upgradeLintDirectory(): string
{
    $directory = sys_get_temp_dir().'/fsa-test-lint-'.bin2hex(random_bytes(4));
    mkdir($directory);

    return $directory;
}

it('finds no concurrent index build or drop in a migration or version step the upgrade child runs', function (): void {
    $migrations = glob(database_path('migrations/*.php')) ?: [];

    expect($migrations)->not->toBeEmpty()
        ->and(upgradeLintDirectories())->toContain(database_path('migrations'))
        ->and(upgradeLintOffending(upgradeLintDirectories(), VersionSteps::STEPS))->toBe([]);
    foreach (UPGRADE_LINT_ALLOWED as $name) {
        expect(is_file(database_path('migrations/'.$name)))->toBeTrue($name)
            ->and(strcmp($name, UPGRADE_LINT_BASELINE))->toBeLessThanOrEqual(0);
    }
});

it('lints a new migration whose name is backdated before the 1.0.0 baseline (review A12)', function (): void {
    $directory = upgradeLintDirectory();
    file_put_contents($directory.'/2026_01_01_000000_backdated_index.php', "<?php DB::statement('CREATE INDEX CONCURRENTLY x ON t (c)');\n");
    file_put_contents($directory.'/'.UPGRADE_LINT_ALLOWED[0], "<?php DB::statement('CREATE INDEX CONCURRENTLY y ON t (c)');\n");

    $offending = upgradeLintOffending([$directory], []);

    expect(array_map(basename(...), $offending))->toEqualCanonicalizing(['2026_01_01_000000_backdated_index.php', UPGRADE_LINT_ALLOWED[0]]);
    array_map(unlink(...), glob($directory.'/*.php') ?: []);
    rmdir($directory);
});

it('lints the migration paths packages register, as the upgrade child runs them (review A12)', function (): void {
    $directory = upgradeLintDirectory();
    file_put_contents($directory.'/2030_01_01_000000_package_index.php', "<?php DB::statement('drop index concurrently if exists x');\n");
    app('migrator')->path($directory);

    $offending = upgradeLintOffending(upgradeLintDirectories(), []);

    expect(upgradeLintDirectories())->toContain($directory)
        ->and(array_map(basename(...), $offending))->toBe(['2030_01_01_000000_package_index.php']);
    unlink($directory.'/2030_01_01_000000_package_index.php');
    rmdir($directory);
});

it('lints the version steps (review A12)', function (): void {
    $directory = upgradeLintDirectory();
    $suffix = bin2hex(random_bytes(4));
    foreach (['Concurrent' => "CREATE INDEX CONCURRENTLY s_{$suffix} ON t (c)", 'Plain' => 'UPDATE t SET c = 1'] as $kind => $sql) {
        file_put_contents("{$directory}/{$kind}{$suffix}.php", "<?php\nfinal class LintStep{$kind}{$suffix} implements App\\Domain\\Platform\\Instance\\Upgrade\\VersionStep {\n"
            ."public function since(): string { return '2.1.0'; }\npublic function name(): string { return 'lint'; }\n"
            ."public function run(Illuminate\\Database\\Connection \$db): void { \$db->statement('{$sql}'); }\n}\n");
        require "{$directory}/{$kind}{$suffix}.php";
    }

    $offending = upgradeLintOffending([], ["LintStepConcurrent{$suffix}", "LintStepPlain{$suffix}"]);

    expect(array_map(basename(...), $offending))->toBe(["Concurrent{$suffix}.php"]);
    array_map(unlink(...), glob($directory.'/*.php') ?: []);
    rmdir($directory);
});

it('recognises a concurrent build, a concurrent drop and the ConcurrentIndex helper', function (string $source, bool $concurrent): void {
    expect(upgradeLintConcurrent($source))->toBe($concurrent);
})->with([
    ["DB::statement('CREATE INDEX CONCURRENTLY x ON t (c)');", true],
    ["DB::statement('drop index concurrently if exists x');", true],
    ["ConcurrentIndex::create('x', 'ON t (c)');", true],
    ["Schema::table('t', fn (\$table) => \$table->index('c'));", false],
]);
