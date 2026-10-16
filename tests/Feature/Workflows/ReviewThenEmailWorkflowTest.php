<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Submissions\Models\SubmissionEvent;
use App\Domain\Workflows\Enums\RunStatus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Feature\Mail\MailFixtures;
use Tests\Feature\Workflows\WorkflowFixtures as W;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/*
 * §8.3 AC at feature level: a "review then email PDF to customer" workflow end to end — submit → review task →
 * changes requested → resubmit (number kept) → approve → branded mail with the PDF of the resubmitted data to
 * the customer.email value; a duplicate event sends no second mail.
 */

function acDecide(FieldAppScenario $s, User $user, int $taskId, array $body): TestResponse
{
    return test()->withHeaders([...apiHeaders($user, $s->org), 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/tasks/{$taskId}/complete", $body);
}

it('runs "review then email PDF to customer" end to end', function (): void {
    DocumentFixtures::fakeDisk();          // render_on_submit on, own disk
    DocumentFixtures::fakeGotenberg();
    config(['workflows.complete_without_review' => true]);
    $notifier = W::notifier();
    $s = FieldAppScenario::make();
    $tenant = app(TenantContext::class);
    $reviewer = W::reviewer($s->org);
    $group = W::group($s->org, 'Prüfer', $reviewer);
    W::workflow($s->org, [
        W::reviewStep($group, 24),
        W::step('send_email', [
            'to_fields' => ['customer.email'], 'document' => 'attachment',
            'subject' => 'Ihr Servicebericht {{ submission.number }}',
            'body' => "Guten Tag {{ recipient.name }},\n\nanbei erhalten Sie Ihren Servicebericht von {{ org.name }}.",
        ]),
    ]);
    $draft = DocumentFixtures::draft($s);
    $tenant->run($s->org, function () use ($draft): void {
        $data = $draft->data;
        $data->customer_email = 'kunde@example.com';
        $draft->data = $data;
        $draft->save();
    });

    // 1. Submit → review task, in_review.
    FieldAppApi::submit($s, $s->worker, $draft->uuid, $draft->revision)->assertOk();
    $submission = W::fresh($s, $draft);
    $number = $submission->number;
    expect($submission->status)->toBe(SubmissionStatus::InReview);

    // 2. The reviewer requests changes.
    acDecide($s, $reviewer, W::task($s->org, $submission)->id, ['outcome' => 'reject', 'comment' => 'Bitte Seriennummer ergänzen'])->assertOk()
        ->assertJsonPath('data.submission.status', 'changes_requested');

    // 3. The worker edits and resubmits (number kept) → a new run and a new task.
    $fresh = W::fresh($s, $submission);
    $data = json_decode((string) json_encode($fresh->data), true);
    FieldAppApi::put($s, $s->worker, $fresh->uuid, [...$data, 'work_done' => 'Schalter getauscht, Seriennummer 4711'], $fresh->revision)->assertOk();
    FieldAppApi::submit($s, $s->worker, $fresh->uuid, $fresh->revision + 1)->assertOk()->assertJsonPath('data.submission.number', $number);
    expect(W::fresh($s, $submission)->status)->toBe(SubmissionStatus::InReview)
        ->and(MailFixtures::arrayTransport()->messages())->toHaveCount(0);

    // 4. Approve → the mail with the PDF goes to the customer; the submission completes.
    acDecide($s, $reviewer, W::task($s->org, $submission)->id, ['outcome' => 'approve'])->assertOk();

    $mails = $tenant->run($s->org, fn () => MailMessage::query()->get());
    $email = MailFixtures::lastSentEmail();
    $latest = $tenant->run($s->org, fn () => Rendition::query()->where('submission_uuid', $submission->uuid)->orderByDesc('id')->first());
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->recipient_email)->toBe('kunde@example.com')
        ->and($mails[0]->status)->toBe(MailStatus::Sent)
        ->and($email->getSubject())->toBe("Ihr Servicebericht {$number}")
        ->and((string) $email->getHtmlBody())->toContain('Guten Tag Beispiel GmbH,')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getFilename())->toBe($number.'.pdf')
        ->and(str_starts_with($email->getAttachments()[0]->getBody(), '%PDF'))->toBeTrue()
        ->and($latest?->created_at->greaterThanOrEqualTo(W::fresh($s, $submission)->submitted_at))->toBeTrue()
        ->and(W::fresh($s, $submission)->status)->toBe(SubmissionStatus::Completed)
        ->and(W::runs($s->org)->pluck('status')->all())->toBe([RunStatus::Rejected, RunStatus::Completed])
        ->and($notifier->recipientsOf(NotificationEvent::ChangesRequested))->toBe([$s->worker->id])
        ->and($notifier->recipientsOf(NotificationEvent::SubmissionApproved))->toBe([$s->worker->id])
        ->and($notifier->sentFor(NotificationEvent::ReviewAssigned))->toHaveCount(2);

    // 5. A duplicate of the resubmit event starts nothing and sends nothing.
    $row = $tenant->run($s->org, fn () => SubmissionEvent::query()->where('submission_uuid', $submission->uuid)->where('to_status', 'submitted')->orderByDesc('id')->first());
    event(new SubmissionStatusChanged($row->id, $submission->uuid, $s->org->id, 'changes_requested', 'submitted', $s->worker->id));
    expect(W::runs($s->org))->toHaveCount(2)
        ->and($tenant->run($s->org, fn () => MailMessage::query()->count()))->toBe(1)
        ->and(MailFixtures::arrayTransport()->messages())->toHaveCount(1);
});
