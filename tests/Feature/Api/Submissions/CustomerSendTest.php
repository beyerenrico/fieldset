<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Submissions\InboxScenario;
use Tests\Support\FieldAppScenario;

/* M3-T08: POST /submissions/{uuid}/send (docs/api/field-app-v1.md). Invoice: roles customer.email/name. */

beforeEach(function (): void {
    $this->s = FieldAppScenario::make('invoice');
    $this->s->org->forceFill(['locale' => 'en'])->save();
});

/** @param  array<string, mixed>  $data */
function sendInvoice(string $status = 'submitted', array $data = [], ?User $author = null): Submission
{
    $s = test()->s;

    return app(TenantContext::class)->run($s->org, fn (): Submission => InboxScenario::submission(
        $s->form, $author ?? $s->worker, $status, $status === 'draft' ? null : 'RE-2026-'.random_int(10000, 99999), $status === 'draft' ? null : '2026-10-16 08:00',
        $data + ['customer_name' => 'Musterbau AG', 'customer_email' => 'einkauf@musterbau.example'],
    ));
}

/** @param  array<string, mixed>  $body */
function postSend(User $user, string $uuid, array $body = [], ?string $key = null): TestResponse
{
    return test()->withHeaders([...apiHeaders($user, test()->s->org), 'Idempotency-Key' => $key ?? (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$uuid}/send", $body);
}

function sendsOf(string $uuid): Collection
{
    return app(TenantContext::class)->run(test()->s->org, fn () => DocumentSend::query()->where('submission_uuid', $uuid)->orderBy('id')->get());
}

it('defaults the recipient to the customer.email / customer.name values and waits for the PDF', function (): void {
    $submission = sendInvoice();

    $response = postSend($this->s->worker, $submission->uuid)->assertStatus(202);

    $send = sendsOf($submission->uuid)->sole();
    expect($response->json('data.send'))->toBe([
        'id' => $send->id, 'status' => 'waiting_for_document',
        'recipient' => ['email' => 'einkauf@musterbau.example', 'name' => 'Musterbau AG'],
    ])->and($send->requested_by)->toBe($this->s->worker->id)
        ->and(app(TenantContext::class)->run($this->s->org, fn () => MailMessage::query()->count()))->toBe(0);
});

it('uses an explicit recipient over the defaults', function (): void {
    $submission = sendInvoice();

    postSend($this->s->worker, $submission->uuid, ['email' => ' office@other.example ', 'name' => 'Frau Weber'])
        ->assertStatus(202)
        ->assertJsonPath('data.send.recipient', ['email' => 'office@other.example', 'name' => 'Frau Weber']);
});

it('validates the email with JSON pointers', function (array $body, array $data, string $code): void {
    $submission = sendInvoice(data: $data);

    postSend($this->s->worker, $submission->uuid, $body)->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.0.pointer', '/email')
        ->assertJsonPath('error.details.0.code', $code);
    expect(sendsOf($submission->uuid))->toBeEmpty();
})->with([
    'no email anywhere' => [[], ['customer_email' => null], 'required'],
    'invalid explicit email' => [['email' => 'not-an-email'], [], 'email'],
    'invalid default email' => [[], ['customer_email' => 'broken@'], 'email'],
    'email of wrong type' => [['email' => ['a@b.example']], [], 'string'],
]);

it('refuses drafts with 409 not_submitted', function (): void {
    postSend($this->s->worker, sendInvoice('draft')->uuid)->assertStatus(409)->assertJsonPath('error.code', 'not_submitted');
});

it('refuses void submissions and versions without sendToCustomer with 409 send_not_allowed', function (): void {
    postSend($this->s->worker, sendInvoice('void')->uuid)->assertStatus(409)->assertJsonPath('error.code', 'send_not_allowed');

    app(TenantContext::class)->run($this->s->org, function (): void {
        $draft = $this->s->form->draft_definition;
        $draft->settings->sendToCustomer = false;
        $this->s->form->forceFill(['draft_definition' => $draft])->save();
        app(PublishForm::class)->handle($this->s->form->refresh(), 'v2', $this->s->owner);
    });
    $submission = sendInvoice();
    expect($submission->formVersion->version)->toBe(2);

    postSend($this->s->worker, $submission->uuid)->assertStatus(409)->assertJsonPath('error.code', 'send_not_allowed');
});

it('lets the creator, reviewers and managers send, but not other members who can see it', function (): void {
    $peer = memberOf($this->s->org, SystemRole::FieldWorker);
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $viewer = memberOf($this->s->org, SystemRole::Viewer);
    customerSendShareGroup($peer, $this->s->worker, $reviewer);
    $submission = sendInvoice(author: $peer);

    postSend($this->s->worker, $submission->uuid)->assertStatus(409)->assertJsonPath('error.code', 'send_not_allowed');
    postSend($viewer, $submission->uuid)->assertStatus(409)->assertJsonPath('error.code', 'send_not_allowed');
    postSend($peer, $submission->uuid)->assertStatus(202);
    postSend($reviewer, $submission->uuid)->assertStatus(202);
    postSend($this->s->owner, $submission->uuid)->assertStatus(202);
    expect(sendsOf($submission->uuid)->pluck('requested_by')->all())->toBe([$peer->id, $reviewer->id, $this->s->owner->id]);
});

function customerSendShareGroup(User ...$users): void
{
    $org = test()->s->org;
    app(TenantContext::class)->run($org, function () use ($org, $users): void {
        $group = Group::query()->create(['name' => 'Crew', 'share_submissions' => true]);
        foreach ($users as $user) {
            DB::table('group_user')->insert(['organization_id' => $org->id, 'group_id' => $group->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
}

it('answers 404 for submissions the user cannot see', function (): void {
    $stranger = memberOf($this->s->org, SystemRole::FieldWorker);

    postSend($stranger, sendInvoice()->uuid)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    postSend($stranger, 'not-a-uuid')->assertNotFound();
});

it('replays the stored 202 for a repeated Idempotency-Key and sends only once', function (): void {
    $submission = sendInvoice();
    $first = postSend($this->s->worker, $submission->uuid, [], 'send-key-0001')->assertStatus(202);

    $replay = postSend($this->s->worker, $submission->uuid, [], 'send-key-0001')->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json())->toBe($first->json())->and(sendsOf($submission->uuid))->toHaveCount(1);
    postSend($this->s->worker, $submission->uuid, ['email' => 'x@y.example'], 'send-key-0001')
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    postSend($this->s->worker, $submission->uuid, [], 'bad')->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
});

it('reports can_send in the submission object', function (): void {
    $submitted = sendInvoice();
    $draft = sendInvoice('draft');
    $viewer = memberOf($this->s->org, SystemRole::Viewer);
    $get = fn (User $user, Submission $s): TestResponse => test()->withHeaders(apiHeaders($user, $this->s->org))->getJson("/api/v1/submissions/{$s->uuid}");

    expect($get($this->s->worker, $submitted)->json('data.submission.can_send'))->toBeTrue()
        ->and($get($this->s->worker, $draft)->json('data.submission.can_send'))->toBeFalse()
        ->and($get($this->s->owner, $submitted)->json('data.submission.can_send'))->toBeTrue()
        ->and($get($viewer, $submitted)->json('data.submission.can_send'))->toBeFalse();
});
