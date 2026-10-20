<?php

declare(strict_types=1);

use App\Console\Commands\RestoreDrillCommand;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Attachment;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;
use Tests\Support\Instance\RestoreDatabases;

/*
 * `instance:restore-drill` (R41, plan U17, ADR 0085) on a committed `…restore_drill_…` database: counts, audit heads,
 * files against the storage archive's members, one render into a temporary directory (Gotenberg faked), APP keys and
 * migrations. It never writes a file to a storage disk, records no rendition or audit entry, and refuses any other
 * database.
 */

const DRILL_SUFFIX = 'restore_drill_t';

beforeEach(function (): void {
    RestoreDatabases::target(DRILL_SUFFIX);
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg(2);
    $this->members = '';
    app()->bind(RestoreDrillCommand::STDIN, fn () => fn (): string => $this->members);
});

afterEach(function (): void {
    RestoreDatabases::drop([DRILL_SUFFIX]);
});

/** @return array{0: int, 1: array<string, mixed>} */
function drillRun(array $options = []): array
{
    $code = Artisan::call('instance:restore-drill', ['--json' => true, ...$options]);

    return [$code, json_decode(trim(Artisan::output()), true)];
}

/** A submitted service report with its signature, plus two local attachments (one of them missing from the archive). */
function drillSubmission(): FieldAppScenario
{
    $scenario = FieldAppScenario::make();
    $submission = DocumentFixtures::submit($scenario, DocumentFixtures::draft($scenario));
    app(TenantContext::class)->run($scenario->org, function () use ($submission): void {
        foreach (['kept', 'lost'] as $name) {
            Attachment::factory()->create(['submission_uuid' => $submission->uuid, 'disk' => 'local', 'path' => "attachments/drill/{$name}.jpg", 'kind' => 'photo', 'element_key' => 'photos']);
        }
    });

    return $scenario;
}

/** @param  list<array{name: string, status: string}>  $checks */
function drillStatus(array $checks, string $name): ?string
{
    return array_column($checks, 'status', 'name')[$name] ?? null;
}

it('reports counts, audit heads, files and a render, and leaves storage and the audit log alone', function (): void {
    drillSubmission();
    $this->members = "./\n./private/\n./private/attachments/drill/kept.jpg\n";
    $files = Storage::disk(DocumentFixtures::DISK)->allFiles();
    $entries = DB::table('activity_log')->count();
    $renditions = DB::table('renditions')->count();

    [$code, $json] = drillRun();
    $data = $json['data'];

    expect($code)->toBe(0, json_encode($json))
        ->and($json['status'])->toBe('ok')
        ->and($data['database'])->toBe(RestoreDatabases::name(DRILL_SUFFIX))
        ->and($data['counts'])->toMatchArray(['organizations' => 1, 'submissions' => 1, 'renditions' => $renditions])
        ->and($data['audit']['epoch'])->toBe(1)
        ->and($data['audit']['intact'])->toBeTrue()
        ->and($data['audit']['heads'])->not->toBe([])
        ->and($data['files'])->toBe(['archive' => true, 'referenced' => 2, 'missing' => 1, 'sample' => ['private/attachments/drill/lost.jpg']])
        ->and($data['render'])->toMatchArray(['status' => 'rendered', 'pages' => 2])
        ->and($data['render']['bytes'])->toBeGreaterThan(100)
        ->and($data['migrations'])->toBe(['pending' => 0, 'unknown' => 0])
        ->and(drillStatus($data['checks'], 'files'))->toBe('warn')
        ->and(drillStatus($data['checks'], 'render'))->toBe('pass')
        ->and(Storage::disk(DocumentFixtures::DISK)->allFiles())->toBe($files)
        ->and(DB::table('renditions')->count())->toBe($renditions)
        ->and(DB::table('activity_log')->count())->toBe($entries);
});

it('warns without a submission to render and without a storage archive', function (): void {
    makeOrganization();

    [$code, $json] = drillRun(['--no-storage' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['render']['status'])->toBe('skipped')
        ->and($json['data']['files']['archive'])->toBeFalse()
        ->and(drillStatus($json['data']['checks'], 'render'))->toBe('warn')
        ->and(drillStatus($json['data']['checks'], 'files'))->toBe('warn');
});

it('fails with the whole report when the APP keys open nothing, the render fails or a migration is unknown', function (string $case): void {
    $scenario = drillSubmission();
    match ($case) {
        'decrypt' => DB::table('webhook_endpoints')->insert(['organization_id' => $scenario->org->id, 'name' => 'hook', 'url' => 'https://hooks.example.com',
            'secret' => new Encrypter(random_bytes(32), 'aes-256-cbc')->encrypt('s', false), 'created_at' => now(), 'updated_at' => now()]),
        'render' => DocumentFixtures::$gotenbergFailure = 503,
        default => DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_a_newer_release', 'batch' => 99]),
    };

    [$code, $json] = drillRun();

    expect($code)->toBe(1)
        ->and($json['status'])->toBe('failed')
        ->and($json['errors'][0]['code'])->toBe('drill_failed')
        ->and(drillStatus($json['data']['checks'], ['decrypt' => 'decrypt', 'render' => 'render', 'migration' => 'migrations'][$case]))->toBe('fail')
        ->and($json['data']['counts']['submissions'])->toBe(1);
})->with(['decrypt', 'render', 'migration']);

it('refuses any database that is not a drill database', function (): void {
    RestoreDatabases::target('rt');

    [$code, $json] = drillRun();

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('not_a_drill_database');
});
