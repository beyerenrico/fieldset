<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\ChainVerifier;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Platform\Impersonation\EndImpersonation;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use App\Domain\Platform\Impersonation\StartImpersonation;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Platform/Support.php';

/*
 * ASVS V7.1.4 (ADR 0038, ADR 0041): every entry written while a platform operator impersonates a member names
 * the operator (`impersonator` {id, email}), inside the hash chain; normal requests never carry it.
 */

beforeEach(function (): void {
    $this->org = makeOrganization(name: 'Acme GmbH');
    $this->owner = ownerOf($this->org);
    $this->operator = platformOperator();
    actingAsPlatformOperator($this->operator);
    clearAuditLog();
});

function auditImpersonateOwner(): Impersonation
{
    $impersonation = app(StartImpersonation::class)->handle(test()->operator, test()->org, test()->owner, 'Ticket 4711: invitation does not arrive');
    app(TenantContext::class)->set(test()->org);

    return $impersonation;
}

it('names the operator on every entry written while impersonating, inside the hash chain', function (): void {
    auditImpersonateOwner();
    app(InviteMember::class)->handle($this->owner, $this->org, 'neu@acme.example', 'Neu', [SystemRole::FieldWorker]);
    $system = app(AuditLogger::class)->system('privacy.retention_applied');

    $invited = Activity::query()->where('event', 'member.invited')->sole();
    expect($invited->causer_id)->toBe($this->owner->id)
        ->and($invited->properties['impersonator'])->toBe(['id' => $this->operator->id, 'email' => $this->operator->email])
        ->and($system->refresh()->properties['impersonator']['id'])->toBe($this->operator->id)
        // The start entry is the operator's own (causer = operator), written before the session switched.
        ->and(Activity::query()->where('event', 'platform.impersonation_started')->sole()->properties->has('impersonator'))->toBeFalse()
        ->and(app(ChainVerifier::class)->verify()->intact())->toBeTrue();

    // Removing the mark afterwards breaks the entry's hash.
    DB::statement('ALTER TABLE activity_log DISABLE TRIGGER activity_log_append_only_trg');
    DB::table('activity_log')->where('id', $invited->id)->update(['properties' => json_encode([...$invited->properties->except('impersonator')->all()])]);
    DB::statement('ALTER TABLE activity_log ENABLE TRIGGER activity_log_append_only_trg');
    expect(implode("\n", app(ChainVerifier::class)->verify()->problems))->toContain("entry #{$invited->id}");
});

it('never names an operator on a normal request, after the impersonation ended, or for another signed-in user', function (): void {
    $impersonation = auditImpersonateOwner();
    app(EndImpersonation::class)->handle($impersonation, EndImpersonation::BY_OPERATOR);
    $afterEnd = app(AuditLogger::class)->record('form.created', null, [], $this->owner);

    actingInAdmin($this->owner, $this->org);
    $normal = app(AuditLogger::class)->record('form.created', null, [], $this->owner);

    // A stale impersonation in the session while someone else is signed in (ended_by=logout path).
    $now = CarbonImmutable::now();
    app(ImpersonationStore::class)->put(new Impersonation($this->operator->id, $this->owner->id, $this->org->id, 'Ticket 4711: stale', $now, $now->addMinutes(30)));
    $other = memberOf($this->org, SystemRole::Admin);
    actingInAdmin($other, $this->org);
    $stale = app(AuditLogger::class)->record('form.created', null, [], $other);

    foreach ([$afterEnd, $normal, $stale] as $entry) {
        expect($entry->refresh()->properties->has('impersonator'))->toBeFalse();
    }
    expect(Activity::query()->where('event', 'platform.impersonation_ended')->sole()->properties->has('impersonator'))->toBeFalse();
});

it('shows the operator in the audit log and filters impersonated entries', function (): void {
    $impersonation = auditImpersonateOwner();
    $byOperator = app(AuditLogger::class)->record('form.created', null, ['name' => 'Service report'], $this->owner);
    app(EndImpersonation::class)->handle($impersonation, EndImpersonation::BY_OPERATOR);
    actingInAdmin($this->owner, $this->org);
    $byOwner = app(AuditLogger::class)->record('form.updated', null, [], $this->owner);
    $via = __('audit.impersonation.via', ['operator' => $this->operator->email]);

    Livewire::test(ListAuditLogs::class)
        ->assertSee($via)
        ->filterTable('impersonated', true)
        ->assertCanSeeTableRecords([$byOperator])
        ->assertCanNotSeeTableRecords([$byOwner])
        ->resetTableFilters()
        ->mountTableAction('view', $byOperator)
        ->assertMountedActionModalSee(__('audit.fields.impersonator'))
        ->assertMountedActionModalSee($this->operator->email);
});
