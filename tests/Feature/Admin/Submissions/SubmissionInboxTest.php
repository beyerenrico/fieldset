<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Livewire\Livewire;
use Tests\Feature\Admin\Submissions\InboxScenario;

/* M3-T09 admin submissions inbox: list, filters and the §8.6 "View all submissions" row. */

beforeEach(function (): void {
    $this->s = InboxScenario::make();
});

it('lists submitted work newest first and hides drafts by default', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->s->workerDelivery, $this->s->peerReport, $this->s->workerReport], inOrder: true)
        ->assertCanNotSeeTableRecords([$this->s->workerDraft])
        ->assertSee('SR-2026-00001')
        ->assertSee('Walter Worker');
});

it('filters by status, form, submitter and submission date, and searches the number', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);
    $s = $this->s;

    Livewire::test(ListSubmissions::class)->filterTable('status', ['draft'])
        ->assertCanSeeTableRecords([$s->workerDraft])->assertCanNotSeeTableRecords($s->nonDrafts());
    Livewire::test(ListSubmissions::class)->filterTable('form_id', $s->designerForm->id)
        ->assertCanSeeTableRecords([$s->workerDelivery])->assertCanNotSeeTableRecords([$s->workerReport, $s->peerReport]);
    Livewire::test(ListSubmissions::class)->filterTable('created_by', $s->peer->id)
        ->assertCanSeeTableRecords([$s->peerReport])->assertCanNotSeeTableRecords([$s->workerReport, $s->workerDelivery]);
    Livewire::test(ListSubmissions::class)->filterTable('submitted_at', ['from' => '2026-10-02', 'until' => '2026-10-04'])
        ->assertCanSeeTableRecords([$s->peerReport])->assertCanNotSeeTableRecords([$s->workerReport, $s->workerDelivery]);
    Livewire::test(ListSubmissions::class)->searchTable('LS-2026')
        ->assertCanSeeTableRecords([$s->workerDelivery])->assertCanNotSeeTableRecords([$s->workerReport, $s->peerReport]);
});

it('offers only forms and submitters of visible submissions as filter options', function (): void {
    actingInAdmin($this->s->designer, $this->s->org);

    $table = Livewire::test(ListSubmissions::class)->instance()->getTable();

    expect($table->getFilter('form_id')->getOptions())->toBe([$this->s->designerForm->id => $this->s->designerForm->name])
        ->and($table->getFilter('created_by')->getOptions())->toBe([$this->s->worker->id => 'Walter Worker']);
});

it('shows all submissions to owners, admins and viewers', function (SystemRole $role): void {
    $user = $role === SystemRole::Owner ? $this->s->owner : memberOf($this->s->org, $role);
    actingInAdmin($user, $this->s->org);

    Livewire::test(ListSubmissions::class)->assertCanSeeTableRecords($this->s->nonDrafts());
})->with([SystemRole::Owner, SystemRole::Admin, SystemRole::Viewer]);

it('shows designers the submissions of forms they created plus their own', function (): void {
    $own = app(TenantContext::class)->run($this->s->org, fn () => InboxScenario::submission($this->s->ownerForm, $this->s->designer, 'submitted', 'SR-2026-00003', '2026-10-06 08:00'));
    actingInAdmin($this->s->designer, $this->s->org);

    Livewire::test(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->s->workerDelivery, $own])
        ->assertCanNotSeeTableRecords([$this->s->workerReport, $this->s->peerReport]);
});

it('shows reviewers the submissions of their sharing groups plus their own', function (): void {
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    actingInAdmin($reviewer, $this->s->org);
    Livewire::test(ListSubmissions::class)->assertCanNotSeeTableRecords($this->s->nonDrafts());

    $this->s->shareGroup($reviewer, $this->s->peer);

    Livewire::test(ListSubmissions::class)
        ->assertCanSeeTableRecords([$this->s->peerReport])
        ->assertCanNotSeeTableRecords([$this->s->workerReport, $this->s->workerDelivery]);
});

it('keeps field workers out of the inbox', function (): void {
    $this->actingAs($this->s->worker)
        ->get(SubmissionResource::getUrl('index', tenant: $this->s->org))
        ->assertForbidden();
});

it('opens the inbox for every admin role and allows listing per policy', function (SystemRole $role): void {
    $user = memberOf($this->s->org, $role);

    $this->actingAs($user)->get(SubmissionResource::getUrl('index', tenant: $this->s->org))->assertOk();
    app(TenantContext::class)->run($this->s->org, fn () => expect($user->can('viewAny', Submission::class))->toBeTrue());
})->with([SystemRole::Admin, SystemRole::Designer, SystemRole::Reviewer, SystemRole::Viewer]);

it('never lists submissions of another organization', function (): void {
    $other = InboxScenario::make();
    actingInAdmin($this->s->owner, $this->s->org);

    Livewire::test(ListSubmissions::class)
        ->assertCanSeeTableRecords($this->s->nonDrafts())
        ->assertCanNotSeeTableRecords($other->nonDrafts());
});

it('denies listing without any submission view permission', function (): void {
    $user = User::factory()->create();
    app(TenantContext::class)->run($this->s->org, fn () => expect($user->can('viewAny', Submission::class))->toBeFalse());
});
