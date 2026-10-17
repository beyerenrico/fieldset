<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\RequestCustomerSend;
use App\Domain\Mail\CustomerSend\CustomerRecipient;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Submissions\Models\IdempotencyKey;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

/*
 * M6 (ADR 0035 "Backend"): customer send is idempotent by key on document_sends, so a lost response
 * replayed after the stored idempotency keys were pruned (24 h) never emails the customer twice.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    DocumentFixtures::fakeGotenberg();
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

function m6SubmittedReport(): Submission
{
    $s = test()->s;
    $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s));

    return app(TenantContext::class)->run($s->org, fn (): Submission => $submission->refresh());
}

/** @param  array<string, mixed>  $body */
function m6Send(Submission $submission, string $key, array $body = ['email' => 'kunde@example.com', 'name' => 'Frau Weber']): TestResponse
{
    $s = test()->s;

    return test()->withHeaders([...apiHeaders($s->worker, $s->org), 'Idempotency-Key' => $key])
        ->postJson("/api/v1/submissions/{$submission->uuid}/send", $body);
}

function m6PruneIdempotencyKeys(): void
{
    test()->travel(25)->hours();
    IdempotencyKey::query()->withoutTenant()->where('created_at', '<', now()->subHours(24))->delete();
}

/** @return array{sends: int, mails: int} */
function m6Counts(Submission $submission): array
{
    return app(TenantContext::class)->run(test()->s->org, fn (): array => [
        'sends' => DocumentSend::query()->where('submission_uuid', $submission->uuid)->count(),
        'mails' => MailMessage::query()->where('submission_uuid', $submission->uuid)->count(),
    ]);
}

it('returns the existing send for a key replayed after the stored responses were pruned', function (): void {
    $submission = m6SubmittedReport();
    $first = m6Send($submission, 'send-key-durable-1')->assertStatus(202)->assertJsonPath('data.send.status', 'queued');

    m6PruneIdempotencyKeys();
    expect(IdempotencyKey::query()->withoutTenant()->count())->toBe(0);
    $replay = m6Send($submission, 'send-key-durable-1')->assertStatus(202)->assertHeaderMissing('Idempotent-Replayed');

    expect($replay->json('data.send'))->toBe($first->json('data.send'))
        ->and(m6Counts($submission))->toBe(['sends' => 1, 'mails' => 1]);
});

it('keeps the original recipient when a pruned key is replayed with another body', function (): void {
    $submission = m6SubmittedReport();
    $first = m6Send($submission, 'send-key-durable-2')->assertStatus(202);

    m6PruneIdempotencyKeys();
    $replay = m6Send($submission, 'send-key-durable-2', ['email' => 'other@example.com'])->assertStatus(202);

    expect($replay->json('data.send'))->toBe($first->json('data.send'))
        ->and($replay->json('data.send.recipient.email'))->toBe('kunde@example.com')
        ->and(m6Counts($submission))->toBe(['sends' => 1, 'mails' => 1]);
});

it('creates a new send for a new key', function (): void {
    $submission = m6SubmittedReport();
    $first = m6Send($submission, 'send-key-durable-3')->assertStatus(202);

    $second = m6Send($submission, 'send-key-durable-4')->assertStatus(202);

    expect($second->json('data.send.id'))->not->toBe($first->json('data.send.id'))
        ->and(m6Counts($submission))->toBe(['sends' => 2, 'mails' => 2]);
});

it('does not count a replayed key against the per-submission cap', function (): void {
    config(['messaging.customer_send.per_submission_per_day' => 1]);
    $submission = m6SubmittedReport();
    m6Send($submission, 'send-key-durable-5')->assertStatus(202);
    IdempotencyKey::query()->withoutTenant()->delete();

    m6Send($submission, 'send-key-durable-5')->assertStatus(202);
    m6Send($submission, 'send-key-durable-6')->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
});

it('stores the key per submission and leaves sends without a key (admin inbox) unconstrained', function (): void {
    $submission = m6SubmittedReport();
    $recipient = new CustomerRecipient('kunde@example.com', null);

    $sends = $this->tenant->run($this->s->org, fn (): array => [
        app(RequestCustomerSend::class)->handle($this->s->owner, $submission, $recipient),
        app(RequestCustomerSend::class)->handle($this->s->owner, $submission, $recipient),
        app(RequestCustomerSend::class)->handle($this->s->owner, $submission, $recipient, 'inbox-key-0001'),
        app(RequestCustomerSend::class)->handle($this->s->owner, $submission, $recipient, 'inbox-key-0001'),
    ]);

    expect(array_map(fn (DocumentSend $send): ?string => $send->idempotency_key, $sends))->toBe([null, null, 'inbox-key-0001', 'inbox-key-0001'])
        ->and($sends[3]->id)->toBe($sends[2]->id)
        ->and($sends[1]->id)->not->toBe($sends[0]->id)
        ->and(m6Counts($submission)['sends'])->toBe(3);
});

it('enforces one send per organization, submission and key in the database', function (): void {
    $submission = m6SubmittedReport();
    $insert = fn () => $this->tenant->run($this->s->org, fn () => DocumentSend::query()->forceCreate([
        'submission_uuid' => $submission->uuid, 'recipient_email' => 'kunde@example.com', 'idempotency_key' => 'db-key-00001',
    ]));
    $insert();

    expect(fn () => DB::transaction($insert))->toThrow(UniqueConstraintViolationException::class);
});
