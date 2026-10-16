<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\Notifier;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Exports\StartExport;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Policies\ReportExportPolicy;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Reports\ReportsScenario;

/*
 * Filament's own export download route (/filament/exports/{export}/download) only checks that the user
 * owns the export, without any tenant. Report exports are served by our tenant-scoped route only
 * (ADR 0033), so Filament's route must refuse them — also for their requester.
 */

beforeEach(function (): void {
    $disk = ReportsScenario::fakeExportDisk();
    app()->instance(Notifier::class, new FakeNotifier);
    $this->s = ReportsScenario::make();
    $this->s->submit($this->s->worker, ['customer' => 'Neu KG', 'amount' => '0.20', 'kind' => 'repair']);
    $this->export = app(TenantContext::class)->run($this->s->org, fn (): ReportExport => app(StartExport::class)
        ->handle($this->s->owner, $this->s->form, ExportFormat::Csv, new ReportFilters, 'Europe/Berlin', null, 'en'))->refresh();
    // Where Filament's downloaders look: without the policy the owner check alone would stream this.
    Storage::disk($disk)->put("filament_exports/{$this->export->id}/0000000000000001.csv", "Neu KG\n");
    Storage::disk($disk)->put("filament_exports/{$this->export->id}/headers.csv", "Customer\n");
});

it('refuses report exports on Filament\'s download route, even for their requester', function (string $format): void {
    expect($this->export->file_path)->not->toBeNull();

    $this->actingAs($this->s->owner)
        ->get(route('filament.exports.download', ['export' => $this->export->id, 'format' => $format]))
        ->assertForbidden();
})->with(['csv', 'xlsx']);

it('refuses signed Filament download links for report exports', function (): void {
    $url = URL::signedRoute('filament.exports.download', ['export' => $this->export->id, 'authGuard' => 'web', 'format' => 'csv'], absolute: false);

    $this->actingAs($this->s->owner)->get($url)->assertForbidden();
});

it('denies viewing any plain Filament export record while report exports keep their own policy', function (): void {
    $plain = Export::query()->findOrFail($this->export->id);

    expect(Gate::forUser($this->s->owner)->allows('view', $plain))->toBeFalse()
        ->and(Gate::getPolicyFor(ReportExport::class))->toBeInstanceOf(ReportExportPolicy::class);

    $this->actingAs($this->s->owner)
        ->get(route('admin.exports.download', ['organization' => $this->s->org->slug, 'export' => $this->export->id]))
        ->assertOk();
});
