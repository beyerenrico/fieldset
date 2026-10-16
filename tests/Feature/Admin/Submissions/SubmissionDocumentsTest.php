<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\Feature\Admin\Submissions\InboxScenario;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Feature\Mail\MailFixtures;
use Tests\Support\FieldAppScenario;

/* M3-T04/T08 in the inbox: renditions list, signed download, re-render, send to customer. */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg(pages: 3);
    app()->setLocale('en');
    $this->s = FieldAppScenario::make();
});

function inboxSubmitted(): Submission
{
    $s = test()->s;
    $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s));

    return app(TenantContext::class)->run($s->org, fn (): Submission => $submission->refresh());
}

function inboxRenditions(Submission $submission): Collection
{
    return app(TenantContext::class)->run(test()->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->orderBy('id')->get());
}

it('lists renditions with versions, pages, size, short hash and a signed download link', function (): void {
    $submission = inboxSubmitted();
    $rendition = inboxRenditions($submission)->sole();

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->assertOk()
        ->assertDontSee(__('inbox.detail.no_documents'))
        ->assertSee(['PDF renditions', 'Submitted', 'Form v1', 'v1', '1.0.0', substr($rendition->input_hash, 0, 12)])
        ->assertSeeHtml('data-rendition="'.$rendition->uuid.'"')
        ->assertSeeHtml('/api/v1/documents/'.$rendition->uuid.'?expires=')
        ->assertSeeHtml('user='.$this->s->owner->id);
});

it('re-renders into a new rendition without touching the existing one', function (): void {
    $submission = inboxSubmitted();
    $first = inboxRenditions($submission)->sole();

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->callAction('rerender')
        ->assertHasNoActionErrors()
        ->assertNotified(__('inbox.documents.rerender_queued'));

    [$a, $b] = inboxRenditions($submission)->all();
    expect($b->reason)->toBe(Rendition::REASON_RERENDER)
        ->and($b->created_by)->toBe($this->s->owner->id)
        ->and($b->design_version_id)->toBe($first->design_version_id)
        ->and($a->file_sha256)->toBe($first->file_sha256)
        ->and($a->path)->toBe($first->path);
});

it('offers re-render and send only to users allowed by the policies', function (): void {
    $submission = inboxSubmitted();
    $viewer = memberOf($this->s->org, SystemRole::Viewer);

    actingInAdmin($viewer, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->assertOk()->assertActionHidden('rerender')->assertActionHidden('sendToCustomer');
});

it('hides both actions for drafts', function (): void {
    $draft = DocumentFixtures::draft($this->s);

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $draft->uuid])
        ->assertActionHidden('rerender')->assertActionHidden('sendToCustomer');
});

it('sends to the customer from the inbox, prefilled from the roles, and lists the request', function (): void {
    $s = FieldAppScenario::make('invoice');
    $submission = app(TenantContext::class)->run($s->org, fn (): Submission => InboxScenario::submission(
        $s->form, $s->worker, 'submitted', 'RE-2026-00001', '2026-10-16 08:00', ['customer_name' => 'Musterbau AG', 'customer_email' => 'einkauf@musterbau.example'],
    ));

    actingInAdmin($s->owner, $s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->mountAction('sendToCustomer')
        ->assertActionDataSet(['email' => 'einkauf@musterbau.example', 'name' => 'Musterbau AG'])
        ->setActionData(['email' => 'office@musterbau.example'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(__('mail.customer_send.waiting', ['email' => 'office@musterbau.example']))
        ->assertSee(['Sent to customers', 'Musterbau AG &lt;office@musterbau.example&gt;', 'Waiting for the PDF'], false);

    $send = app(TenantContext::class)->run($s->org, fn () => DocumentSend::query()->sole());
    expect($send->status)->toBe(DocumentSendStatus::WaitingForDocument)->and($send->requested_by)->toBe($s->owner->id);
});

it('validates the recipient in the inbox action', function (): void {
    $submission = inboxSubmitted();

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->callAction('sendToCustomer', data: ['email' => 'not-an-email'])
        ->assertHasActionErrors(['email']);
    expect(MailFixtures::arrayTransport()->messages())->toHaveCount(0);
});
