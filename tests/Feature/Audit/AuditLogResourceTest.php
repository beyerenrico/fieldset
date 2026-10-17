<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Export\ExportAuditLog;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Privacy\Enums\DataExportKind;
use App\Domain\Privacy\Exports\DataExportNotifications;
use App\Domain\Privacy\Models\DataExport;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/* §8.11: read-only audit log resource, tenant-scoped, filters, CSV export (sync and queued). */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    actingInAdmin($this->owner, $this->org);
    clearAuditLog();
});

it('lists only the organization\'s entries and is read-only', function (): void {
    $own = app(AuditLogger::class)->record('form.created', $this->owner, ['name' => 'Service report'], $this->owner);
    $other = makeOrganization(name: 'Other GmbH');
    $foreign = app(TenantContext::class)->run($other, fn () => app(AuditLogger::class)->record('form.created'));
    app(TenantContext::class)->set($this->org);

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee(__('audit.events.form.created'));

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and($this->owner->can('update', $own))->toBeFalse()
        ->and($this->owner->can('delete', $own))->toBeFalse()
        ->and($this->owner->can('view', $foreign))->toBeFalse();
});

it('filters by actor, event, subject type and date range', function (): void {
    $admin = memberOf($this->org, SystemRole::Admin);
    $byOwner = app(AuditLogger::class)->record('form.created', $this->owner, [], $this->owner);
    $byAdmin = app(AuditLogger::class)->record('role.created', $admin, [], $admin);
    $system = app(AuditLogger::class)->system('privacy.retention_applied');
    $group = Group::query()->create(['name' => 'Crew']);
    $groupEntry = Activity::query()->where('subject_type', $group->getMorphClass())->sole();
    $this->travel(-10)->days();
    $old = app(AuditLogger::class)->record('form.updated', $this->owner, [], $this->owner);
    $this->travelBack();

    Livewire::test(ListAuditLogs::class)
        ->filterTable('actor', ['user:'.$admin->id])->assertCanSeeTableRecords([$byAdmin])->assertCanNotSeeTableRecords([$byOwner, $system])
        ->resetTableFilters()
        ->filterTable('actor', ['system'])->assertCanSeeTableRecords([$system])->assertCanNotSeeTableRecords([$byOwner, $byAdmin])
        ->resetTableFilters()
        ->filterTable('event', ['role.created'])->assertCanSeeTableRecords([$byAdmin])->assertCanNotSeeTableRecords([$byOwner])
        ->resetTableFilters()
        ->filterTable('subject_type', [$group->getMorphClass()])->assertCanSeeTableRecords([$groupEntry])->assertCanNotSeeTableRecords([$byOwner])
        ->resetTableFilters()
        ->filterTable('created_at', ['from' => now()->subDays(2)->toDateString(), 'until' => now()->toDateString()])
        ->assertCanSeeTableRecords([$byOwner])->assertCanNotSeeTableRecords([$old]);
});

it('shows the entry detail with the redacted diff', function (): void {
    $entry = app(AuditLogger::class)->record('settings.updated', $this->owner, ['smtp' => ['host' => 'mail.example.com', 'password' => 'hunter2']], $this->owner);

    Livewire::test(ListAuditLogs::class)
        ->mountTableAction('view', $entry)
        ->assertMountedActionModalSee('mail.example.com')
        ->assertMountedActionModalDontSee('hunter2');
});

it('downloads the filtered log as CSV at once and audits the export', function (): void {
    request()->headers->set('User-Agent', '=HYPERLINK("x")');
    app(AuditLogger::class)->record('form.created', $this->owner, ['name' => 'Report', 'api_key' => 'k-123'], $this->owner);
    app(AuditLogger::class)->record('role.created', $this->owner, [], $this->owner);

    $component = Livewire::test(ListAuditLogs::class)
        ->filterTable('event', ['form.created'])
        ->callAction('exportCsv')
        ->assertFileDownloaded();
    $csv = base64_decode((string) data_get($component->effects, 'download.content'));

    expect($csv)->toContain('form.created')->not->toContain('role.created')->toContain(",\"'=HYPERLINK(\"\"x\"\")\",")->not->toContain('k-123')
        ->and(Activity::query()->where('event', 'audit.exported')->sole()->properties['queued'])->toBeFalse();
});

it('queues large exports and notifies the requester with a signed link', function (): void {
    Storage::fake('local');
    $notifier = new FakeNotifier;
    app()->instance(Notifier::class, $notifier);
    config(['audit.export.sync_max_rows' => 1]);
    app(AuditLogger::class)->record('form.created', $this->owner, [], $this->owner);
    app(AuditLogger::class)->record('form.updated', $this->owner, [], $this->owner);

    Livewire::test(ListAuditLogs::class)->callAction('exportCsv')->assertNotified(__('audit.export.queued'));

    $export = DataExport::query()->sole();
    expect($export->kind)->toBe(DataExportKind::AuditLog)->and($export->rows)->toBeGreaterThanOrEqual(2)
        ->and(Storage::disk('local')->get((string) $export->path))->toContain('form.updated')
        ->and($notifier->hasSentTo($this->owner, NotificationEvent::ExportReady))->toBeTrue()
        ->and(Activity::query()->where('event', 'audit.exported')->sole()->properties['queued'])->toBeTrue();
    $this->get((string) DataExportNotifications::downloadUrl($export))->assertOk();
    expect(ExportAuditLog::syncMaxRows())->toBe(1);
});

it('is visible to audit.view holders only', function (): void {
    actingInAdmin(memberOf($this->org, SystemRole::Designer), $this->org);

    $this->get(AuditLogResource::getUrl('index'))->assertForbidden();
});
