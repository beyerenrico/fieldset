<?php

declare(strict_types=1);

use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Workflows\Enums\RunStatus;
use Illuminate\Support\Collection;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Feature\Mail\MailFixtures;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4-T02: send_email, notify, set_status, generate_document. */

beforeEach(function (): void {
    $this->notifier = W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
});

function mails(): Collection
{
    return app(TenantContext::class)->run(test()->s->org, fn () => MailMessage::query()->orderBy('id')->get());
}

it('emails fixed addresses, field values, the submitter and group members once each, with tokens', function (): void {
    $member = memberOf($this->s->org, SystemRole::Reviewer);
    $group = W::group($this->s->org, 'Büro', $member);
    W::workflow($this->s->org, [W::step('send_email', [
        'to_addresses' => ['office@example.com', 'OFFICE@example.com', 'not-an-address'],
        'to_fields' => ['customer_name'],                     // not an address: skipped
        'to_submitter' => true,
        'to_group_ids' => [$group->id],
        'subject' => 'Neuer Bericht {{ submission.number }} ({{ form.name }})',
        'body' => "Hallo {{ recipient.name }},\n\n{{ org.name }}",
    ])]);

    $submission = W::submitted($this->s);

    $sent = mails();
    expect($sent->pluck('recipient_email')->all())->toBe(['office@example.com', $this->s->worker->email, $member->email])
        ->and($sent->pluck('submission_uuid')->unique()->all())->toBe([$submission->uuid])
        ->and($sent[0]->subject)->toBe("Neuer Bericht {$submission->number} ({$this->s->form->name})")
        ->and(W::runs($this->s->org)->sole()->stepRuns[0]->output['mail_message_ids'])->toHaveCount(3)
        ->and(MailFixtures::lastSentEmail()->getHtmlBody())->toContain('Hallo '.$member->name);
});

it('reads recipients from a semantic role (customer.email) and fails without any valid recipient', function (): void {
    $form = W::workflow($this->s->org, [W::step('send_email', ['to_fields' => ['customer.email'], 'subject' => 'Ihr Bericht'])]);

    W::submitted($this->s);   // the service report has no customer.email value

    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe(__('workflows.errors.no_recipients'))
        ->and(mails())->toHaveCount(0)
        ->and($form->id)->toBe($run->workflow_id);
});

it('sends the PDF as an expiring link and renders it when missing', function (): void {
    DocumentFixtures::fakeGotenberg();
    W::workflow($this->s->org, [W::step('send_email', ['to_addresses' => ['kunde@example.com'], 'subject' => 'PDF', 'body' => 'Link: {{ document.link }}', 'document' => 'link'])]);

    $submission = W::submitted($this->s);   // render_on_submit is off: the step renders

    $rendition = $this->tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());
    $email = MailFixtures::lastSentEmail();
    expect($email->getAttachments())->toBe([])
        ->and((string) $email->getHtmlBody())->toContain('/documents/links/')
        ->and($this->tenant->run($this->s->org, fn () => DocumentLink::query()->sole()->rendition_id))->toBe($rendition->id)
        ->and(W::runs($this->s->org)->sole()->stepRuns[0]->output['rendition_id'])->toBe($rendition->id);
});

it('sets a status through the state machine and fails on forbidden transitions', function (): void {
    W::workflow($this->s->org, [W::step('set_status', ['status' => 'completed']), W::step('set_status', ['status' => 'void'])]);
    $submission = W::submitted($this->s);
    expect(W::fresh($this->s, $submission)->status)->toBe(SubmissionStatus::Void)
        ->and(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);

    W::workflow($this->s->org, [W::step('set_status', ['status' => 'approved'])]);
    W::submitted($this->s);
    $failed = W::runs($this->s->org)->last();
    expect($failed?->status)->toBe(RunStatus::Failed)
        ->and($failed?->error)->toContain(SubmissionStatus::Approved->label());
});

it('generates a document with the chosen design version and records it', function (): void {
    DocumentFixtures::fakeGotenberg();
    $design = $this->tenant->run($this->s->org, function () {
        app(CreateDesign::class)->handle('Standard', $this->s->owner);   // the default design

        return app(CreateDesign::class)->handle('Kunde', $this->s->owner);
    });
    W::workflow($this->s->org, [W::step('generate_document', ['design_key' => $design->key])]);

    $submission = W::submitted($this->s);

    $rendition = $this->tenant->run($this->s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->sole());
    $output = W::runs($this->s->org)->sole()->stepRuns[0]->output;
    expect($rendition->reason)->toBe(Rendition::REASON_WORKFLOW)
        ->and($rendition->design_version_id)->toBe($design->current_version_id)
        ->and($output['design_key'])->toBe($design->key)
        ->and($output['design_version_id'])->toBe($design->current_version_id)
        ->and($output['rendition_uuid'])->toBe($rendition->uuid)
        ->and($output['request_id'])->toBe($rendition->request_id);
});

it('fails generate_document for an unknown design', function (): void {
    W::workflow($this->s->org, [W::step('generate_document', ['design_key' => 'gibt-es-nicht'])]);

    W::submitted($this->s);

    expect(W::runs($this->s->org)->sole()->error)->toContain('gibt-es-nicht');
});

it('notifies users, groups and roles on the selected channels', function (): void {
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $designer = memberOf($this->s->org, SystemRole::Designer);
    $group = W::group($this->s->org, 'Crew', $designer);
    W::workflow($this->s->org, [W::step('notify', [
        'user_ids' => [$this->s->owner->id], 'group_ids' => [$group->id], 'roles' => ['reviewer'],
        'channels' => [Notifier::CHANNEL_DATABASE], 'message' => 'Neuer Bericht eingegangen',
    ])]);

    $submission = W::submitted($this->s);

    $sent = $this->notifier->sentFor(NotificationEvent::WorkflowNotice)[0];
    expect($sent['recipients'])->toEqualCanonicalizing([$this->s->owner->id, $designer->id, $reviewer->id])
        ->and($sent['channels'])->toBe([Notifier::CHANNEL_DATABASE])
        ->and($sent['message']->params['message'])->toBe('Neuer Bericht eingegangen')
        ->and($sent['message']->submissionUuid)->toBe($submission->uuid)
        ->and($sent['message']->organizationId)->toBe($this->s->org->id);
});
