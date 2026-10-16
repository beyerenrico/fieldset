<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Exports\StartExport;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Models\SavedReport;
use App\Filament\Admin\Pages\FormExplorer;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use App\Filament\Admin\Resources\SavedReports\SavedReportResource;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use Livewire\Livewire;
use Tests\Feature\Reports\ReportsScenario;

/*
 * M5-T06 / BUILD_PROMPT §8.6 "Reports and exports" column: Owner, Admin, Designer, Reviewer and Viewer
 * — not Field Worker. Exported rows follow the "View submissions" row.
 */

beforeEach(function (): void {
    ReportsScenario::fakeExportDisk();
    $this->s = ReportsScenario::make();
});

function roleUser(ReportsScenario $s, SystemRole $role): User
{
    return $role === SystemRole::Owner ? $s->owner : memberOf($s->org, $role);
}

it('grants reports and exports per the §8.6 matrix', function (SystemRole $role, bool $allowed): void {
    $user = roleUser($this->s, $role);
    actingInAdmin($user, $this->s->org);

    expect(FormExplorer::canAccess())->toBe($allowed)
        ->and(SavedReportResource::canViewAny())->toBe($allowed)
        ->and(SavedReportResource::canCreate())->toBe($allowed)
        ->and($user->can('create', ReportExport::class))->toBe($allowed);
})->with([
    'owner' => [SystemRole::Owner, true],
    'admin' => [SystemRole::Admin, true],
    'designer' => [SystemRole::Designer, true],
    'reviewer' => [SystemRole::Reviewer, true],
    'field worker' => [SystemRole::FieldWorker, false],
    'viewer' => [SystemRole::Viewer, true],
]);

it('exports exactly the rows of the "View submissions" row for each role', function (SystemRole $role, string $expected): void {
    $s = $this->s;
    $user = roleUser($s, $role);
    $peer = memberOf($s->org, SystemRole::FieldWorker);
    if ($role === SystemRole::Reviewer) {
        $s->group('Crew', $user, $s->worker);
    }
    $rows = [
        'worker' => $s->submit($s->worker, ['amount' => '1']),
        'peer' => $s->submit($peer, ['amount' => '2']),
        'own' => $role === SystemRole::FieldWorker ? null : $s->submit($user, ['amount' => '3']),
    ];

    $export = app(TenantContext::class)->run($s->org, fn (): ReportExport => app(StartExport::class)
        ->handle($user, $s->form, ExportFormat::Csv, new ReportFilters, 'UTC'))->refresh();

    $visible = collect(explode(',', $expected))->map(fn (string $key) => $rows[$key]?->uuid)->filter()->count();
    expect($export->successful_rows)->toBe($visible);
})->with([
    'owner: all' => [SystemRole::Owner, 'worker,peer,own'],
    'admin: all' => [SystemRole::Admin, 'worker,peer,own'],
    'viewer: all' => [SystemRole::Viewer, 'worker,peer,own'],
    'designer: own forms + own' => [SystemRole::Designer, 'own'],         // the form was created by the owner
    'reviewer: group + own' => [SystemRole::Reviewer, 'worker,own'],
]);

it('lets the creator, and owners/admins for shared reports, edit a saved report', function (): void {
    $s = $this->s;
    $designer = memberOf($s->org, SystemRole::Designer);
    $admin = memberOf($s->org, SystemRole::Admin);
    $viewer = memberOf($s->org, SystemRole::Viewer);
    [$shared, $private] = app(TenantContext::class)->run($s->org, fn (): array => [
        SavedReport::factory()->create(['form_id' => $s->form->id, 'visibility' => 'organization', 'created_by' => $designer->id]),
        SavedReport::factory()->create(['form_id' => $s->form->id, 'visibility' => 'private', 'created_by' => $designer->id]),
    ]);
    app(TenantContext::class)->set($s->org);

    expect($designer->can('update', $shared))->toBeTrue()
        ->and($admin->can('update', $shared))->toBeTrue()
        ->and($viewer->can('view', $shared))->toBeTrue()
        ->and($viewer->can('update', $shared))->toBeFalse()
        ->and($admin->can('view', $private))->toBeFalse()
        ->and($s->worker->can('view', $shared))->toBeFalse();
});

it('links the explorer from the forms list and the inbox', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(ListForms::class)->assertTableActionVisible('explore', $this->s->form);
    Livewire::test(ListSubmissions::class)->assertActionVisible('explore')
        ->filterTable('form_id', $this->s->form->id)
        ->assertActionHasUrl('explore', FormExplorer::getUrl(['form' => $this->s->form->id]));
});
