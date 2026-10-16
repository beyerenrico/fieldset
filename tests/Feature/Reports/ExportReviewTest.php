<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\Delivery\RenderedNotification;
use App\Domain\Notifications\Delivery\StoredNotification;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Exports\ExportRefused;
use App\Domain\Reports\Exports\RunExport;
use App\Domain\Reports\Exports\StartExport;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Models\ReportExport;
use App\Filament\Admin\Pages\FormExplorer;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Reports\ReportsScenario;

/* Review M5: export limits (M5), snapshot paging in explorer order (M4, L5), currency (M1), German CSV (U8). */

beforeEach(function (): void {
    $this->disk = ReportsScenario::fakeExportDisk();
    app()->instance(Notifier::class, new FakeNotifier);
    $this->s = ReportsScenario::make([1 => ['currency' => 'CHF']]);
    $s = $this->s;
    $this->old = $s->submit($s->worker, ['customer' => 'Alt', 'amount' => '1234.50'], version: 1, submittedAt: '2026-10-01 09:00');
    $this->mid = $s->submit($s->worker, ['customer' => 'Mitte', 'amount' => '0.10', 'items' => [['qty' => '1.5', 'price' => '65.00']]], version: 2, submittedAt: '2026-10-05 09:00');
    $this->new = $s->submit($s->owner, ['customer' => 'Neu', 'amount' => '0.20'], version: 3, submittedAt: '2026-10-09 09:00');
    RateLimiter::clear('report-export:'.$s->owner->id);
});

function requestExport(ReportsScenario $s, ExportFormat $format, ?array $uuids = null, string $locale = 'en'): ReportExport
{
    return app(TenantContext::class)->run($s->org, fn (): ReportExport => app(StartExport::class)
        ->handle($s->owner, $s->form, $format, new ReportFilters, 'Europe/Berlin', $uuids, $locale))->refresh();
}

it('caps "export all" at reports.exports.max_rows with a clear message', function (): void {
    config(['reports.exports.max_rows' => 2]);

    expect(fn () => requestExport($this->s, ExportFormat::Csv))->toThrow(ExportRefused::class, __('reports.export.too_many_rows', ['max' => '2', 'rows' => '3']))
        ->and(requestExport($this->s, ExportFormat::Csv, [$this->new->uuid])->successful_rows)->toBe(1);   // a selection is capped by max_selected

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::withQueryParams(['form' => $this->s->form->id])->test(FormExplorer::class)->callAction('export_csv')
        ->assertNotified(Notification::make()->warning()->title(__('reports.export.refused'))->body(__('reports.export.too_many_rows', ['max' => '2', 'rows' => '3'])));
});

it('refuses a new export while the user has too many pending or running ones', function (): void {
    config(['reports.exports.max_pending' => 2]);
    app(TenantContext::class)->run($this->s->org, fn () => ReportExport::factory()->count(2)->create([
        'user_id' => $this->s->owner->id, 'form_id' => $this->s->form->id, 'status' => 'pending',
    ]));

    expect(fn () => requestExport($this->s, ExportFormat::Csv))->toThrow(ExportRefused::class, __('reports.export.too_many_pending', ['max' => 2]));
});

it('throttles export requests per user', function (): void {
    config(['reports.exports.per_minute' => 1]);

    requestExport($this->s, ExportFormat::Csv);

    expect(fn () => requestExport($this->s, ExportFormat::Csv))->toThrow(ExportRefused::class);
});

it('queues exports on the dedicated exports queue that Horizon works', function (): void {
    Queue::fake();

    requestExport($this->s, ExportFormat::Csv);

    Queue::assertPushedOn('exports', RunExport::class);
    $queues = collect(config('horizon.defaults'))->pluck('queue')->flatten()->all();
    expect(config('reports.exports.queue'))->toBe('exports')->and($queues)->toContain('exports');
});

it('writes rows in explorer order from a snapshot of the selection, never paging by offset', function (): void {
    config(['reports.exports.chunk_size' => 1]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $export = requestExport($this->s, ExportFormat::Csv);
    $zip = new ZipArchive;
    $zip->open(Storage::disk($this->disk)->path((string) $export->file_path));
    $lines = array_values(array_filter(explode("\n", (string) $zip->getFromName('submissions.csv'))));
    $zip->close();

    expect(array_map(fn (string $l): string => explode(',', $l)[0], array_slice($lines, 1)))->toBe([$this->new->number, $this->mid->number, $this->old->number])
        ->and(array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), ' offset ')))->toBe([]);
});

it('exports the currency of each submission version next to money values', function (): void {
    $export = requestExport($this->s, ExportFormat::Csv);
    $zip = new ZipArchive;
    $zip->open(Storage::disk($this->disk)->path((string) $export->file_path));
    $main = (string) $zip->getFromName('submissions.csv');
    $items = (string) $zip->getFromName('items.csv');
    $zip->close();

    expect($main)->toContain('Amount,"Amount (currency)"')
        ->toContain(',1234.50,CHF,')
        ->toContain(',0.20,EUR,')
        ->and($items)->toContain('Price,"Price (currency)"')->toContain(',65.00,EUR');
});

it('writes German CSV exports with semicolons and decimal commas, keeping every digit', function (): void {
    $export = requestExport($this->s, ExportFormat::Csv, locale: 'de');
    $zip = new ZipArchive;
    $zip->open(Storage::disk($this->disk)->path((string) $export->file_path));
    $main = (string) $zip->getFromName('submissions.csv');
    $zip->close();

    expect($main)->toStartWith("\u{FEFF}Nummer;Status")->toContain(';1234,50;CHF;')->toContain(';0,10;EUR;');
});

it('shows a failed export as a failure and a finished one with a download button (review U5)', function (): void {
    $ready = new NotificationMessage(NotificationEvent::ExportReady, 't', 'b', [], '/admin/x/exports/1', null, null, null, 'reports.notifications.download');
    $failed = new NotificationMessage(NotificationEvent::ExportReady, 't', 'b', [], null, null, null, 'danger');
    $render = fn (?string $url): RenderedNotification => new RenderedNotification('Title', 'Body', 'de', $url);

    $readyPayload = StoredNotification::payload($ready, $render('/admin/x/exports/1'), null);
    $failedPayload = StoredNotification::payload($failed, $render(null), null);

    expect($readyPayload['status'])->toBe('success')->and($readyPayload['actions'][0]['label'])->toBe('Herunterladen')
        ->and($failedPayload['status'])->toBe('danger')->and($failedPayload['actions'])->toBe([])
        ->and($failedPayload['icon'])->not->toBe($readyPayload['icon']);

    config(['reports.exports.disk' => 'missing-disk']);
    $notifier = new FakeNotifier;
    app()->instance(Notifier::class, $notifier);
    expect(fn () => requestExport($this->s, ExportFormat::Csv))->toThrow(InvalidArgumentException::class);
    $message = $notifier->sentFor(NotificationEvent::ExportReady)[0]['message'];
    expect($message->status)->toBe('danger')->and($message->url)->toBeNull();
});

it('answers an expired download link with a friendly 410 page', function (): void {
    $export = requestExport($this->s, ExportFormat::Csv);
    $export->forceFill(['expires_at' => now()->subMinute()])->save();
    $url = route('admin.exports.download', ['organization' => $this->s->org->slug, 'export' => $export->id]);

    $this->actingAs($this->s->owner)->get($url)->assertStatus(410)
        ->assertSee(__('reports.export.expired.title'))->assertSee(__('reports.export.expired.body', ['days' => 7]));
});
