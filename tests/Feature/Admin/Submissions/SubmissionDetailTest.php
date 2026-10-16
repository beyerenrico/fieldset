<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Admin\Submissions\InboxScenario;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* M3-T09 detail view: header, data with labels of the pinned version, events, documents placeholder. */

it('renders a submitted service report read-only with labels, tables, money and the signature', function (): void {
    Storage::fake('local');
    app()->setLocale('de');
    $s = FieldAppScenario::make();
    $uuid = FieldAppApi::readyDraft($s, $s->worker);
    FieldAppApi::submit($s, $s->worker, $uuid, 1)->assertOk();
    $submission = app(TenantContext::class)->run($s->org, fn () => Submission::query()->with('attachments')->findOrFail($uuid));
    $signature = $submission->attachments->sole();

    auth()->shouldUse('web'); // the API calls switched the default guard to Sanctum
    actingInAdmin($s->owner, $s->org);
    Livewire::test(ViewSubmission::class, ['record' => $uuid])
        ->assertOk()
        ->assertSee($submission->number)
        ->assertSee('Servicebericht')
        ->assertSee('v1')
        ->assertSee(__('submissions.status.submitted'))
        ->assertSee(['Kunde & Auftrag', 'Kunde', 'Beispiel GmbH', 'Reparatur', 'Door switch misaligned'])
        ->assertSee(['Material &amp; Arbeitszeit', 'Beschreibung', 'Line 1', 'Line 2', 'Std.'], false)
        ->assertSee(['97,50 EUR', '195,00 EUR', '232,05 EUR', 'Ja'])
        ->assertSee('/admin/'.$s->org->slug.'/attachments/'.$signature->uuid, false)
        ->assertSee(__('inbox.detail.events'))
        ->assertSee(__('inbox.detail.no_documents'));
});

it('keeps the labels of the pinned version after the form changes', function (): void {
    $scenario = InboxScenario::make();
    $submission = app(TenantContext::class)->run($scenario->org, fn (): Submission => InboxScenario::submission(
        $scenario->ownerForm, $scenario->worker, 'submitted', 'SR-2026-00009', '2026-10-07 09:00', ['customer_name' => 'Musterbau AG'],
    ));
    app(TenantContext::class)->run($scenario->org, function () use ($scenario): void {
        $draft = $scenario->ownerForm->draft_definition;
        $draft->pages[0]->elements[0]->label = 'Auftraggeber';
        $scenario->ownerForm->forceFill(['draft_definition' => $draft])->save();
        app(PublishForm::class)->handle($scenario->ownerForm->refresh(), 'v2', $scenario->owner);
    });

    actingInAdmin($scenario->owner, $scenario->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->assertSee('Musterbau AG')->assertSee('Kunde')->assertDontSee('Auftraggeber');
});

it('lists status events with actor and comment', function (): void {
    $scenario = InboxScenario::make();
    app(TenantContext::class)->run($scenario->org, function () use ($scenario): void {
        $scenario->peerReport->events()->create(['from_status' => 'draft', 'to_status' => 'submitted', 'actor_id' => $scenario->peer->id]);
        $scenario->peerReport->events()->create(['from_status' => 'submitted', 'to_status' => 'in_review', 'actor_id' => null, 'comment' => 'Automatisch geprüft']);
    });
    app()->setLocale('en');

    actingInAdmin($scenario->owner, $scenario->org);
    Livewire::test(ViewSubmission::class, ['record' => $scenario->peerReport->uuid])
        ->assertSeeInOrder(['Draft', 'Submitted', 'Petra Peer', 'Submitted', 'In review', 'System', 'Automatisch geprüft']);
});

it('opens the detail only for users who may see the submission', function (SystemRole $role, bool $allowed): void {
    $scenario = InboxScenario::make();
    $user = $role === SystemRole::Owner ? $scenario->owner : memberOf($scenario->org, $role);
    $url = SubmissionResource::getUrl('view', ['record' => $scenario->workerReport], tenant: $scenario->org);

    $response = $this->actingAs($user)->get($url);

    $allowed ? $response->assertOk()->assertSee('SR-2026-00001') : $response->assertNotFound();
})->with([
    'owner' => [SystemRole::Owner, true],
    'admin' => [SystemRole::Admin, true],
    'viewer' => [SystemRole::Viewer, true],
    'designer of another form' => [SystemRole::Designer, false],
    'reviewer without group' => [SystemRole::Reviewer, false],
]);

it('opens the detail for the designer who created the form and for a reviewer of the group', function (): void {
    $scenario = InboxScenario::make();
    $reviewer = memberOf($scenario->org, SystemRole::Reviewer);
    $scenario->shareGroup($reviewer, $scenario->worker);

    $this->actingAs($scenario->designer)->get(SubmissionResource::getUrl('view', ['record' => $scenario->workerDelivery], tenant: $scenario->org))->assertOk();
    $this->actingAs($reviewer)->get(SubmissionResource::getUrl('view', ['record' => $scenario->workerReport], tenant: $scenario->org))->assertOk();
});

it('forbids field workers and hides submissions of other organizations', function (): void {
    $scenario = InboxScenario::make();
    $other = InboxScenario::make();

    $this->actingAs($scenario->worker)
        ->get(SubmissionResource::getUrl('view', ['record' => $scenario->workerReport], tenant: $scenario->org))->assertForbidden();
    $this->actingAs($other->owner)
        ->get(SubmissionResource::getUrl('view', ['record' => $scenario->workerReport], tenant: $other->org))->assertNotFound();
    $this->actingAs($other->owner)
        ->get(SubmissionResource::getUrl('view', ['record' => $scenario->workerReport], tenant: $scenario->org))->assertNotFound();
});

it('offers no create, edit or delete', function (): void {
    $scenario = InboxScenario::make();
    actingInAdmin($scenario->owner, $scenario->org);

    expect(SubmissionResource::canCreate())->toBeFalse()
        ->and(SubmissionResource::canEdit($scenario->workerReport))->toBeFalse()
        ->and(SubmissionResource::canDelete($scenario->workerReport))->toBeFalse()
        ->and(array_keys(SubmissionResource::getPages()))->toBe(['index', 'view'])
        ->and(SubmissionResource::getRecordTitle($scenario->workerReport))->toBe('SR-2026-00001')
        ->and(SubmissionResource::getRecordTitle($scenario->workerDraft))->toBe(__('inbox.label').' '.substr($scenario->workerDraft->uuid, 0, 8));
});
