<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Exports\StartExport;
use App\Domain\Reports\Filters\FieldFilter;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Models\ReportExport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Reports\ReportsScenario;

/* M5-T02 exports: XLSX (repeaters on their own sheet), CSV (ZIP with repeaters), PDF ZIP, download, audit. */

beforeEach(function (): void {
    $this->disk = ReportsScenario::fakeExportDisk();
    $this->notifier = new FakeNotifier;
    app()->instance(Notifier::class, $this->notifier);
    $this->s = ReportsScenario::make();
    $s = $this->s;
    $this->old = $s->submit($s->worker, ['customer' => '=HYPERLINK("x")', 'amount' => '1234.50', 'notes' => 'Old note', 'kind' => 'repair'], version: 1, submittedAt: '2026-10-01 09:00');
    $this->mid = $s->submit($s->worker, ['customer' => 'Mitte AG', 'amount' => '0.10', 'kind' => 'maintenance', 'visit_date' => '2026-10-04',
        'items' => [['description' => 'Switch', 'qty' => '1', 'price' => '89.00'], ['description' => 'Labour', 'qty' => '1.5', 'price' => '65.00']]], version: 2, submittedAt: '2026-10-05 09:00');
    $this->new = $s->submit($s->owner, ['customer' => 'Neu KG', 'amount' => '0.20', 'kind' => 'repair', 'done' => true], version: 3, submittedAt: '2026-10-09 09:00');
});

function startExport(ReportsScenario $s, ExportFormat $format, ?User $user = null, ?ReportFilters $filters = null, ?array $uuids = null): ReportExport
{
    return app(TenantContext::class)->run($s->org, fn (): ReportExport => app(StartExport::class)
        ->handle($user ?? $s->owner, $s->form, $format, $filters ?? new ReportFilters, 'Europe/Berlin', $uuids, 'en'))->refresh();
}

/** @return array<string, list<list<mixed>>> sheet name => rows */
function readXlsx(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    return $sheets;
}

it('writes an XLSX with exact decimal strings and the repeater rows on their own sheet', function (): void {
    $export = startExport($this->s, ExportFormat::Xlsx);

    expect($export->status)->toBe(ExportStatus::Completed)->and($export->successful_rows)->toBe(3)
        ->and($export->file_name)->toEndWith('.xlsx');
    $sheets = readXlsx(Storage::disk($this->disk)->path((string) $export->file_path));
    $main = $sheets['Submissions'];

    expect(array_keys($sheets))->toBe(['Submissions', 'Items'])
        ->and($main[0])->toBe(['Number', 'Status', 'Submitted by', 'Submitted at', 'Version', 'Client name', 'Amount', 'Amount (currency)', 'Kind', 'Visit date', 'Items', 'Done', 'Notes']);
    $byNumber = collect(array_slice($main, 1))->keyBy(0);
    expect($byNumber[$this->old->number][5])->toBe('=HYPERLINK("x")')          // a string cell, not a formula
        ->and($byNumber[$this->old->number][6])->toBe('1234.50')
        ->and($byNumber[$this->old->number][7])->toBe('EUR')                    // currency of the submission's version
        ->and($byNumber[$this->mid->number][6])->toBe('0.10')
        ->and($byNumber[$this->mid->number][8])->toBe('Maintenance')
        ->and($byNumber[$this->mid->number][9])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($byNumber[$this->mid->number][10])->toBe(2)
        ->and($byNumber[$this->new->number][11])->toBeTrue()
        ->and($byNumber[$this->old->number][12])->toBe('Old note')
        ->and($byNumber[$this->old->number][4])->toBe(1)
        ->and($sheets['Items'])->toBe([
            ['Number', 'Row', 'Description', 'Qty', 'Price', 'Price (currency)'],
            [$this->mid->number, '1', 'Switch', '1', '89.00', 'EUR'],
            [$this->mid->number, '2', 'Labour', '1.5', '65.00', 'EUR'],
        ]);
});

it('writes a CSV, as a ZIP with one more CSV per repeater, and neutralizes formulas', function (): void {
    $export = startExport($this->s, ExportFormat::Csv);

    expect($export->file_name)->toEndWith('.zip');
    $zip = new ZipArchive;
    $zip->open(Storage::disk($this->disk)->path((string) $export->file_path));
    $main = (string) $zip->getFromName('submissions.csv');
    $items = (string) $zip->getFromName('items.csv');
    $zip->close();

    expect($main)->toStartWith("\u{FEFF}Number,Status")
        ->toContain("'=HYPERLINK(\"\"x\"\")")
        ->toContain(',1234.50,')
        ->and($items)->toContain($this->mid->number.',2,Labour,1.5,65.00');
});

it('exports only the rows the user may see and only matching the filters (§8.6)', function (): void {
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $this->s->group('Crew', $reviewer, $this->s->worker);

    expect(startExport($this->s, ExportFormat::Xlsx, $reviewer)->successful_rows)->toBe(2)
        ->and(startExport($this->s, ExportFormat::Xlsx, filters: new ReportFilters(fields: [new FieldFilter('kind', FieldFilter::IN, ['repair'])]))->successful_rows)->toBe(2)
        ->and(startExport($this->s, ExportFormat::Xlsx, uuids: [$this->new->uuid, '00000000-0000-0000-0000-000000000000'])->successful_rows)->toBe(1);
});

it('refuses field workers (§8.6 Reports and exports)', function (): void {
    startExport($this->s, ExportFormat::Csv, $this->s->worker);
})->throws(AuthorizationException::class);

it('audits each export request once and notifies the requester with the download link', function (): void {
    $export = startExport($this->s, ExportFormat::Xlsx);

    $audit = Activity::query()->where('event', 'report.exported')->sole();
    $sent = $this->notifier->sentFor(NotificationEvent::ExportReady);
    expect((string) $audit->subject_id)->toBe((string) $export->id)
        ->and((string) $audit->causer_id)->toBe((string) $this->s->owner->id)
        ->and($audit->properties['rows'])->toBe(3)
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['recipients'])->toBe([$this->s->owner->id])
        ->and($sent[0]['message']->url)->toBe(route('admin.exports.download', ['organization' => $this->s->org->slug, 'export' => $export->id]))
        ->and($sent[0]['message']->params)->toMatchArray(['form' => 'Service report', 'format' => 'XLSX', 'rows' => 3]);
});

it('lets only the requester download, and answers 410 once the export expired', function (): void {
    $export = startExport($this->s, ExportFormat::Xlsx);
    $url = route('admin.exports.download', ['organization' => $this->s->org->slug, 'export' => $export->id]);

    $this->actingAs($this->s->owner)->get($url)->assertOk()->assertDownload((string) $export->file_name);
    $this->actingAs(memberOf($this->s->org, SystemRole::Admin))->get($url)->assertNotFound();
    $this->actingAs(ownerOf(makeOrganization(name: 'Other')))->get($url)->assertNotFound();

    $export->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->actingAs($this->s->owner)->get($url)->assertStatus(410);
});

it('prunes old exports with their files', function (): void {
    $export = startExport($this->s, ExportFormat::Xlsx);
    Storage::disk($this->disk)->assertExists((string) $export->file_path);

    $this->travel(8)->days();
    $this->artisan('model:prune', ['--model' => [ReportExport::class]])->assertSuccessful();

    expect(ReportExport::query()->withoutTenant()->count())->toBe(0);
    Storage::disk($this->disk)->assertMissing((string) $export->file_path);
});

it('zips the latest PDF of each submission, named by number, and reports the ones without a PDF', function (): void {
    Storage::fake('reports-pdfs');
    app(TenantContext::class)->run($this->s->org, function (): void {
        foreach (['old' => 'renditions/old.pdf', 'new' => 'renditions/new.pdf'] as $name => $path) {
            Storage::disk('reports-pdfs')->put($path, "%PDF-1.7 {$name}");
        }
        Rendition::factory()->create(['submission_uuid' => $this->mid->uuid, 'disk' => 'reports-pdfs', 'path' => 'renditions/old.pdf', 'size' => 13, 'created_at' => now()->subDay()]);
        Rendition::factory()->create(['submission_uuid' => $this->mid->uuid, 'disk' => 'reports-pdfs', 'path' => 'renditions/new.pdf', 'size' => 13, 'created_at' => now()]);
    });

    $export = startExport($this->s, ExportFormat::PdfZip);

    $zip = new ZipArchive;
    $zip->open(Storage::disk($this->disk)->path((string) $export->file_path));
    expect($zip->numFiles)->toBe(1)
        ->and($zip->getFromName($this->mid->number.'.pdf'))->toBe('%PDF-1.7 new')
        ->and($export->total_rows)->toBe(3)->and($export->successful_rows)->toBe(1)
        ->and($this->notifier->sentFor(NotificationEvent::ExportReady)[0]['message']->bodyKey)->toBe('reports.notifications.ready_partial.body');
    $zip->close();
});

it('marks an export failed and tells the requester when the job cannot finish', function (): void {
    config(['reports.exports.disk' => 'missing-disk']);

    expect(fn () => startExport($this->s, ExportFormat::Csv))->toThrow(InvalidArgumentException::class);

    $export = app(TenantContext::class)->run($this->s->org, fn () => ReportExport::query()->sole());
    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($this->notifier->sentFor(NotificationEvent::ExportReady)[0]['message']->titleKey)->toBe('reports.notifications.failed.title');
});

it('fails exports whose worker died and tells the requester', function (): void {
    $stuck = app(TenantContext::class)->run($this->s->org, fn (): ReportExport => ReportExport::factory()->create([
        'user_id' => $this->s->owner->id, 'form_id' => $this->s->form->id, 'status' => 'running',
    ]));
    ReportExport::query()->withoutTenant()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(10)]);
    app(TenantContext::class)->clear();

    $this->artisan('reports:sweep-exports')->assertSuccessful();

    expect(ReportExport::query()->withoutTenant()->find($stuck->id)?->status)->toBe(ExportStatus::Failed)
        ->and($this->notifier->hasSentTo($this->s->owner, NotificationEvent::ExportReady))->toBeTrue();
});

it('ignores range bounds of the wrong type instead of failing the export', function (): void {
    $filters = ReportFilters::fromArray(['fields' => [
        ['key' => 'visit_date', 'operator' => 'between', 'values' => ['10.5', '2026-10-31']],
        ['key' => 'amount', 'operator' => 'between', 'values' => ['2026-01-01', '1']],
    ]]);

    expect(startExport($this->s, ExportFormat::Csv, filters: $filters)->successful_rows)->toBe(1)   // mid: visited by 31 Oct, amount ≤ 1
        ->and(FieldFilter::isDay('2026-02-31'))->toBeFalse();
});
