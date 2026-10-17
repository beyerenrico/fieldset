<?php

declare(strict_types=1);

use App\Domain\Audit\AuditEntryView;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Redactor;
use App\Domain\Identity\Models\Group;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use Livewire\Livewire;

/*
 * The audit entry detail reads like a sentence, not a database dump: labelled keys, before → after rows,
 * localized booleans and times (L LT in the viewer's time zone), "hidden (secret)" for redacted values and
 * no raw event key.
 */

beforeEach(function (): void {
    $this->org = makeOrganization(); // Europe/Berlin
    $this->owner = ownerOf($this->org);
    actingInAdmin($this->owner, $this->org);
    clearAuditLog();
    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(8, 30, 0)->utc());
});

it('shows a readable entry detail in German', function (): void {
    app()->setLocale('de');
    $entry = app(AuditLogger::class)->record('privacy.retention_changed', null, [
        'before' => null,
        'after' => ['months' => 24, 'action' => 'anonymize'],
        'remember' => true,
        'queued' => false,
        'expires_at' => '2026-10-17T08:30:00+00:00',
        'smtp' => ['host' => 'mail.example.com', 'password' => 'hunter2'],
    ], $this->owner);

    expect(AuditEntryView::properties($entry, 'Europe/Berlin'))->toMatchArray([
        'Monate' => '— → 24',
        'Aktion' => '— → anonymize',
        'Angemeldet bleiben' => 'Ja',
        'Im Hintergrund' => 'Nein',
        'Läuft ab' => '17.10.2026 10:30',
        'SMTP' => 'Host: mail.example.com; Passwort: verborgen (vertraulich)',
    ]);

    Livewire::test(ListAuditLogs::class)
        ->assertSee('17.10.2026 10:30')
        ->mountTableAction('view', $entry)
        ->assertMountedActionModalSee(['Aufbewahrung geändert', 'Monate', '— → 24', 'Angemeldet bleiben', 'Ja', 'Nein', '17.10.2026 10:30', 'verborgen (vertraulich)'])
        ->assertMountedActionModalDontSee(['privacy.retention_changed', 'hunter2', Redactor::MASK, '2026-10-17T08:30', '"months"']);
});

it('labels attribute changes and shows them as old → new', function (): void {
    app()->setLocale('de');
    $group = Group::query()->create(['name' => 'Crew', 'share_submissions' => false]);
    $group->update(['name' => 'Montage', 'share_submissions' => true]);
    $entry = $group->activitiesAsSubject()->where('event', 'updated')->sole();

    expect(AuditEntryView::changes($entry, 'Europe/Berlin'))->toBe(['Name' => 'Crew → Montage', 'Einreichungen teilen' => 'Nein → Ja']);
});

it('shows the English empty state and US date order in English', function (): void {
    app()->setLocale('en');
    Livewire::test(ListAuditLogs::class)->assertSee('No audit entries yet');

    app(AuditLogger::class)->record('form.created', null, [], $this->owner);
    Livewire::test(ListAuditLogs::class)->assertSee('10/17/2026 10:30 AM');
});

it('shows the German empty state', function (): void {
    app()->setLocale('de');
    Livewire::test(ListAuditLogs::class)->assertSee('Noch keine Audit-Einträge')->assertDontSee('Keine Audit-Log');
});
