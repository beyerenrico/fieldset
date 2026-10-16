<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\DocumentRenderer;
use App\Domain\Documents\Rendering\RenderFailedException;
use App\Domain\Documents\Renditions\GenerateRendition;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\Schemas\SubmissionDocuments;
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

it('lists renditions with versions, pages, size, short hash and a download link that never expires on the page', function (): void {
    $submission = inboxSubmitted();
    $rendition = inboxRenditions($submission)->sole();

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->assertOk()
        ->assertDontSee(__('inbox.detail.no_documents'))
        ->assertSee(['PDF renditions', 'Submitted', 'Form v1', 'v1', DocumentRenderer::VERSION, substr($rendition->input_hash, 0, 12)])
        ->assertSeeHtml('data-rendition="'.$rendition->uuid.'"')
        ->assertSeeHtml(route('admin.renditions.download', ['organization' => $this->s->org->slug, 'rendition' => $rendition->uuid]))
        ->assertDontSeeHtml('/api/v1/documents/'.$rendition->uuid.'?expires=');
});

it('mints a fresh signed download on click, also after the page was open for a long time', function (): void {
    $submission = inboxSubmitted();
    $rendition = inboxRenditions($submission)->sole();
    $url = route('admin.renditions.download', ['organization' => $this->s->org->slug, 'rendition' => $rendition->uuid]);
    $this->travel(2)->hours();

    $redirect = $this->actingAs($this->s->owner)->get($url)->assertRedirect();
    $target = (string) $redirect->headers->get('Location');

    expect($target)->toContain('/api/v1/documents/'.$rendition->uuid.'?')->toContain('user='.$this->s->owner->id);
    $this->get($target)->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('answers 404 on the admin download for foreign organizations and users who cannot see the submission', function (): void {
    $submission = inboxSubmitted();
    $rendition = inboxRenditions($submission)->sole();
    $other = makeOrganization(name: 'Other');
    $outsider = ownerOf($other);
    $designer = memberOf($this->s->org, SystemRole::Designer);

    $this->actingAs($outsider)->get(route('admin.renditions.download', ['organization' => $this->s->org->slug, 'rendition' => $rendition->uuid]))->assertNotFound();
    $this->actingAs($outsider)->get(route('admin.renditions.download', ['organization' => $other->slug, 'rendition' => $rendition->uuid]))->assertNotFound();
    $this->actingAs($designer)->get(route('admin.renditions.download', ['organization' => $this->s->org->slug, 'rendition' => $rendition->uuid]))->assertNotFound();
});

it('shows a permanent render failure and failed sends with fixed texts only', function (): void {
    config(['documents.render_on_submit' => false]);
    $submission = inboxSubmitted();
    app(TenantContext::class)->run($this->s->org, function () use ($submission): void {
        DocumentSend::factory()->create(['submission_uuid' => $submission->uuid]);
        new GenerateRendition($submission->uuid, $this->s->org->id)->failed(RenderFailedException::because(new RuntimeException('SECRET-INTERNAL-DETAIL')));
    });

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->assertSee([__('inbox.documents.failure.title'), __('inbox.documents.failure.render_failed'), __('mail.customer_send.failure.document_failed')])
        ->assertSeeHtml('data-section="rendition-failure"')
        ->assertDontSee('SECRET-INTERNAL-DETAIL');
});

it('formats sizes with the app locale', function (): void {
    $submission = inboxSubmitted();
    app()->setLocale('de');

    $data = app(TenantContext::class)->run($this->s->org, fn (): array => SubmissionDocuments::viewData($submission, $this->s->owner, 'Europe/Berlin'));

    expect($data['renditions'][0]['size'])->toMatch('/^\d+,\d B$/');
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

it('applies the server email rule in the inbox action and keeps the modal open with the field error', function (): void {
    $submission = inboxSubmitted();

    actingInAdmin($this->s->owner, $this->s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->mountAction('sendToCustomer')
        ->setActionData(['email' => 'user@localhost'])
        ->callMountedAction()
        ->assertHasActionErrors(['email'])
        ->assertActionMounted('sendToCustomer');
    expect(app(TenantContext::class)->run($this->s->org, fn () => DocumentSend::query()->count()))->toBe(0);
});

it('sends without a name when the name is cleared in the inbox action', function (): void {
    $s = FieldAppScenario::make('invoice');
    $submission = app(TenantContext::class)->run($s->org, fn (): Submission => InboxScenario::submission(
        $s->form, $s->worker, 'submitted', 'RE-2026-00002', '2026-10-16 08:00', ['customer_name' => 'Musterbau AG', 'customer_email' => 'einkauf@musterbau.example'],
    ));

    actingInAdmin($s->owner, $s->org);
    Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])
        ->mountAction('sendToCustomer')
        ->setActionData(['name' => ''])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(app(TenantContext::class)->run($s->org, fn () => DocumentSend::query()->sole()->recipient_name))->toBeNull();
});

it('reports the send cap in the inbox action without closing the modal', function (): void {
    config(['messaging.customer_send.per_submission_per_day' => 1]);
    $submission = inboxSubmitted();
    actingInAdmin($this->s->owner, $this->s->org);
    $page = Livewire::test(ViewSubmission::class, ['record' => $submission->uuid]);

    $page->callAction('sendToCustomer', data: ['email' => 'kunde@example.com'])->assertHasNoActionErrors();
    $page->mountAction('sendToCustomer')->setActionData(['email' => 'kunde@example.com'])->callMountedAction()
        ->assertNotified(__('mail.customer_send.errors.too_many_for_submission', ['max' => 1]))
        ->assertActionMounted('sendToCustomer');
    expect(app(TenantContext::class)->run($this->s->org, fn () => DocumentSend::query()->count()))->toBe(1);
});

it('throttles re-renders per submission and per user', function (): void {
    [$first, $second, $third] = [inboxSubmitted(), inboxSubmitted(), inboxSubmitted()];
    config(['documents.rerender.per_user_per_minute' => 2]);
    actingInAdmin($this->s->owner, $this->s->org);
    $rerender = fn (Submission $submission) => Livewire::test(ViewSubmission::class, ['record' => $submission->uuid])->callAction('rerender');

    $rerender($first)->assertNotified(__('inbox.documents.rerender_queued'));
    $rerender($first)->assertNotNotified(__('inbox.documents.rerender_queued'));   // per submission: 1 per minute
    $rerender($second)->assertNotified(__('inbox.documents.rerender_queued'));
    $rerender($third)->assertNotNotified(__('inbox.documents.rerender_queued'));   // per user: 2 per minute
    $this->travel(61)->seconds();
    $rerender($third)->assertNotified(__('inbox.documents.rerender_queued'));

    expect(inboxRenditions($first))->toHaveCount(2)->and(inboxRenditions($second))->toHaveCount(2)->and(inboxRenditions($third))->toHaveCount(2);
});
