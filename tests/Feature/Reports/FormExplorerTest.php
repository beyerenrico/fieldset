<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Filament\Admin\Pages\FormExplorer;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Reports\ReportsScenario;

/* M5-T01 submission explorer: columns across versions, type-aware values, filters, search, visibility. */

beforeEach(function (): void {
    $this->s = ReportsScenario::make();
    $s = $this->s;
    $this->old = $s->submit($s->worker, ['customer' => 'Alt GmbH', 'amount' => '1234.50', 'notes' => 'Old note', 'kind' => 'repair'], version: 1, submittedAt: '2026-10-01 09:00');
    $this->mid = $s->submit($s->worker, ['customer' => 'Mitte AG', 'amount' => '0.10', 'kind' => 'maintenance', 'visit_date' => '2026-10-04',
        'items' => [['description' => 'Switch', 'qty' => '1', 'price' => '89.00'], ['description' => 'Labour', 'qty' => '1.5', 'price' => '65.00']]], version: 2, submittedAt: '2026-10-05 09:00');
    $this->new = $s->submit($s->owner, ['customer' => 'Neu KG', 'amount' => '0.20', 'kind' => 'repair', 'visit_date' => '2026-10-08', 'done' => true], version: 3, submittedAt: '2026-10-09 09:00');
    $this->void = $s->submit($s->owner, ['customer' => 'Storno', 'amount' => '5'], version: 3, status: 'void', submittedAt: '2026-10-09 10:00');
});

function explorer(ReportsScenario $s, ?User $user = null): Testable
{
    actingInAdmin($user ?? $s->owner, $s->org);

    return Livewire::withQueryParams(['form' => $s->form->id])->test(FormExplorer::class);
}

it('builds one column per key of all three versions with the newest label', function (): void {
    $table = explorer($this->s)->instance()->getTable();

    expect($table->getColumn('field_customer')->getLabel())->toBe('Client name')
        ->and($table->getColumn('field_notes')->getLabel())->toBe('Notes')
        ->and($table->getColumn('field_visit_date')->getLabel())->toBe('Visit date')
        ->and($table->getColumn('field_items')->getLabel())->toBe('Items')
        ->and($table->getColumn('field_done')->getLabel())->toBe('Done')
        ->and(array_keys($table->getColumns()))->toContain('number', 'status', 'creator.name', 'submitted_at');
});

it('shows the values of every version, formatted by type, and hides void by default', function (): void {
    explorer($this->s)
        ->assertCanSeeTableRecords([$this->new, $this->mid, $this->old], inOrder: true)
        ->assertCanNotSeeTableRecords([$this->void])
        ->assertTableColumnStateSet('field_notes', 'Old note', $this->old)           // key removed in v3
        ->assertTableColumnStateSet('field_amount', '1,234.50 EUR', $this->old)      // decimal string, grouped
        ->assertTableColumnStateSet('field_amount', '0.10 EUR', $this->mid)
        ->assertTableColumnStateSet('field_kind', 'Maintenance', $this->mid)         // option label
        ->assertTableColumnStateSet('field_items', 2, $this->mid)                    // repeater row count
        ->assertTableColumnStateSet('field_done', 'Yes', $this->new)
        ->assertTableColumnStateSet('field_visit_date', '10/08/2026', $this->new)
        ->assertTableColumnStateSet('field_visit_date', '—', $this->old);
});

it('filters by field values per type, status, date, submitter and group, and searches values', function (): void {
    $s = $this->s;
    explorer($s)->filterTable('field_kind', ['repair'])->assertCanSeeTableRecords([$this->old, $this->new])->assertCanNotSeeTableRecords([$this->mid]);
    explorer($s)->filterTable('field_amount', ['min' => '0.15', 'max' => '1000'])->assertCanSeeTableRecords([$this->new])->assertCanNotSeeTableRecords([$this->old, $this->mid]);
    explorer($s)->filterTable('field_done', 'true')->assertCanSeeTableRecords([$this->new])->assertCanNotSeeTableRecords([$this->old, $this->mid]);
    explorer($s)->filterTable('field_done', 'false')->assertCanSeeTableRecords([$this->old, $this->mid])->assertCanNotSeeTableRecords([$this->new]);
    explorer($s)->filterTable('field_visit_date', ['from' => '2026-10-01', 'until' => '2026-10-05'])->assertCanSeeTableRecords([$this->mid])->assertCanNotSeeTableRecords([$this->new, $this->old]);
    explorer($s)->filterTable('status', ['void'])->assertCanSeeTableRecords([$this->void])->assertCanNotSeeTableRecords([$this->new]);
    explorer($s)->filterTable('submitted', ['from' => '2026-10-02', 'until' => '2026-10-06'])->assertCanSeeTableRecords([$this->mid])->assertCanNotSeeTableRecords([$this->old, $this->new]);
    explorer($s)->filterTable('submitter', [$s->worker->id])->assertCanSeeTableRecords([$this->old, $this->mid])->assertCanNotSeeTableRecords([$this->new]);
    $crew = $s->group('Crew', $s->worker);
    explorer($s)->filterTable('group', [$crew->id])->assertCanSeeTableRecords([$this->old, $this->mid])->assertCanNotSeeTableRecords([$this->new]);
    explorer($s)->searchTable('old note')->assertCanSeeTableRecords([$this->old])->assertCanNotSeeTableRecords([$this->mid, $this->new]);
    explorer($s)->searchTable('Walter')->assertCanSeeTableRecords([$this->old, $this->mid])->assertCanNotSeeTableRecords([$this->new]);
});

it('sorts by a field using its typed value', function (): void {
    explorer($this->s)->sortTable('field_amount')->assertCanSeeTableRecords([$this->mid, $this->new, $this->old], inOrder: true)
        ->sortTable('field_amount', 'desc')->assertCanSeeTableRecords([$this->old, $this->new, $this->mid], inOrder: true);
});

it('shows reviewers only the submissions they may see (§8.6)', function (): void {
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $this->s->group('Crew', $reviewer, $this->s->worker);

    explorer($this->s, $reviewer)->assertCanSeeTableRecords([$this->old, $this->mid])->assertCanNotSeeTableRecords([$this->new]);
});

it('keeps field workers out and offers only forms with visible submissions', function (): void {
    actingInAdmin($this->s->worker, $this->s->org);
    expect(FormExplorer::canAccess())->toBeFalse();

    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    actingInAdmin($reviewer, $this->s->org);
    expect(FormExplorer::canAccess())->toBeTrue();
    Livewire::withQueryParams(['form' => $this->s->form->id])->test(FormExplorer::class)
        ->assertSet('formId', null)->assertSee(__('reports.explorer.empty'));
});

it('exports the selected rows in the background and notifies the user', function (): void {
    $disk = ReportsScenario::fakeExportDisk();
    app()->instance(Notifier::class, $fake = new FakeNotifier);

    explorer($this->s)->callTableBulkAction('exportSelectedXlsx', [$this->old, $this->mid])->assertNotified(__('reports.export.started.title'));

    $export = ReportExport::query()->sole();
    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->total_rows)->toBe(2)
        ->and($export->options['uuids'])->toEqualCanonicalizing([$this->old->uuid, $this->mid->uuid])
        ->and($fake->hasSentTo($this->s->owner, NotificationEvent::ExportReady))->toBeTrue();
    Storage::disk($disk)->assertExists((string) $export->file_path);
});

it('exports all rows matching the current filters from the header action', function (): void {
    ReportsScenario::fakeExportDisk();

    explorer($this->s)->filterTable('field_kind', ['repair'])->callAction('export_csv');

    $export = ReportExport::query()->sole();
    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->successful_rows)->toBe(2)
        ->and($export->options['filters']['fields'][0])->toEqual(['key' => 'kind', 'operator' => 'in', 'values' => ['repair']]);
});

it('refuses a bulk selection above the limit instead of truncating it', function (): void {
    ReportsScenario::fakeExportDisk();
    config(['reports.exports.max_selected' => 1]);

    explorer($this->s)->callTableBulkAction('exportSelectedCsv', [$this->old, $this->mid])
        ->assertNotified(__('reports.export.too_many', ['max' => 1]));

    expect(ReportExport::query()->count())->toBe(0);
});
