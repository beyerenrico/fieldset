<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Submissions\InboxScenario;
use Tests\Support\FieldAppScenario;

/* M3 review #9 (caps on "send to customer") and #19 (explicit empty name, docs/api/field-app-v1.md). */

beforeEach(function (): void {
    $this->s = FieldAppScenario::make('invoice');
});

function limitsInvoice(): Submission
{
    $s = test()->s;

    return app(TenantContext::class)->run($s->org, fn (): Submission => InboxScenario::submission(
        $s->form, $s->worker, 'submitted', 'RE-2026-'.random_int(10000, 99999), '2026-10-16 08:00',
        ['customer_name' => 'Musterbau AG', 'customer_email' => 'einkauf@musterbau.example'],
    ));
}

/** @param  array<string, mixed>  $body */
function limitsSend(User $user, string $uuid, array $body = []): TestResponse
{
    return test()->withHeaders([...apiHeaders($user, test()->s->org), 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$uuid}/send", $body);
}

it('caps sends per submission per day with 429 too_many_requests', function (): void {
    config(['messaging.customer_send.per_submission_per_day' => 2]);
    $submission = limitsInvoice();

    limitsSend($this->s->worker, $submission->uuid)->assertStatus(202);
    limitsSend($this->s->worker, $submission->uuid)->assertStatus(202);
    limitsSend($this->s->worker, $submission->uuid)->assertStatus(429)
        ->assertJsonPath('success', false)->assertJsonPath('data', null)
        ->assertJsonPath('error.code', 'too_many_requests');
    limitsSend($this->s->worker, limitsInvoice()->uuid)->assertStatus(202);

    $this->travel(25)->hours();
    limitsSend($this->s->worker, $submission->uuid)->assertStatus(202);
});

it('caps sends per organization per hour', function (): void {
    config(['messaging.customer_send.per_organization_per_hour' => 2]);

    limitsSend($this->s->worker, limitsInvoice()->uuid)->assertStatus(202);
    limitsSend($this->s->worker, limitsInvoice()->uuid)->assertStatus(202);
    limitsSend($this->s->worker, limitsInvoice()->uuid)->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');

    expect(app(TenantContext::class)->run($this->s->org, fn () => DocumentSend::query()->count()))->toBe(2);
});

it('treats an explicit null or empty name as "no name" and an omitted name as the role default', function (mixed $name, ?string $expected): void {
    $body = $name === 'omitted' ? ['email' => 'office@other.example'] : ['email' => 'office@other.example', 'name' => $name];

    limitsSend($this->s->worker, limitsInvoice()->uuid, $body)->assertStatus(202)->assertJsonPath('data.send.recipient.name', $expected);
})->with([
    'omitted' => ['omitted', 'Musterbau AG'],
    'null' => [null, null],
    'empty' => ['', null],
    'blank' => ['   ', null],
    'given' => ['Frau Weber', 'Frau Weber'],
]);
