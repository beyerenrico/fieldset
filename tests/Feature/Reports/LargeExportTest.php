<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Exports\RunExport;
use App\Domain\Reports\Exports\StartExport;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Models\ReportExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Reports\ReportsScenario;

/* BUILD_PROMPT §8.4 AC: an export of 10k submissions completes in the background and notifies the user. */

/** Inserts submitted submissions with projected values in bulk (no per-row projector call). */
function seedSubmissions(ReportsScenario $s, int $count): void
{
    $now = Carbon::parse('2026-10-10 08:00', 'UTC');
    for ($offset = 0; $offset < $count; $offset += 1000) {
        $submissions = $values = [];
        foreach (range($offset + 1, min($count, $offset + 1000)) as $i) {
            $uuid = (string) Str::uuid7();
            $submittedAt = $now->copy()->addSeconds($i);
            $submissions[] = [
                'uuid' => $uuid, 'organization_id' => $s->org->id, 'form_id' => $s->form->id, 'form_version_id' => $s->versions[3]->id,
                'status' => 'submitted', 'data' => '{}', 'revision' => 1, 'number' => sprintf('SR-2026-%05d', $i),
                'created_by' => $s->worker->id, 'submitted_at' => $submittedAt, 'created_at' => $submittedAt, 'updated_at' => $submittedAt,
            ];
            $base = ['organization_id' => $s->org->id, 'form_id' => $s->form->id, 'form_version_id' => $s->versions[3]->id,
                'submission_uuid' => $uuid, 'status' => 'submitted', 'submitted_at' => $submittedAt, 'role' => null, 'value_date' => null];
            $values[] = $base + ['key' => 'customer', 'path' => '/customer', 'value_text' => "Kunde {$i}", 'value_num' => null];
            $values[] = $base + ['key' => 'amount', 'path' => '/amount', 'value_text' => '0.10', 'value_num' => '0.10'];
            $values[] = $base + ['key' => 'items.qty', 'path' => '/items/0/qty', 'value_text' => '2', 'value_num' => '2'];
        }
        DB::table('submissions')->insert($submissions);
        DB::table('submission_values')->insert($values);
    }
}

it('exports 10k submissions in a queued job with flat memory and notifies the user', function (): void {
    $disk = ReportsScenario::fakeExportDisk();
    app()->instance(Notifier::class, $notifier = new FakeNotifier);
    $s = ReportsScenario::make();
    seedSubmissions($s, 10_000);
    Queue::fake();

    $export = app(TenantContext::class)->run($s->org, fn (): ReportExport => app(StartExport::class)
        ->handle($s->owner, $s->form, ExportFormat::Xlsx, new ReportFilters, 'Europe/Berlin', null, 'en'));

    // The request only records and queues the export.
    expect($export->refresh()->status)->toBe(ExportStatus::Pending)->and($export->total_rows)->toBe(10_000);
    Queue::assertPushed(RunExport::class, 1);

    $job = Queue::pushed(RunExport::class)->sole();
    memory_reset_peak_usage();
    $baseline = memory_get_usage();
    app()->call([$job, 'handle']);
    $peak = memory_get_peak_usage() - $baseline;

    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->successful_rows)->toBe(10_000)
        ->and($peak)->toBeLessThan(64 * 1024 * 1024)
        ->and($notifier->hasSentTo($s->owner, NotificationEvent::ExportReady))->toBeTrue();

    $reader = new Reader;
    $reader->open(Storage::disk($disk)->path((string) $export->file_path));
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows[$sheet->getName()] = iterator_count($sheet->getRowIterator());
    }
    $reader->close();
    expect($rows)->toBe(['Submissions' => 10_001, 'Items' => 10_001]);
});
