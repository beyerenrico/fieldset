<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\RetireVersion;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    $this->headers = apiHeaders($this->s->worker, $this->s->org);
});

function putDraft(string $uuid, array $data, ?int $revision = null): TestResponse
{
    $body = ['form_version_id' => test()->s->version->id, 'data' => (object) $data];
    if ($revision !== null) {
        $body['revision'] = $revision;
    }

    return test()->withHeaders(test()->headers)->putJson("/api/v1/submissions/{$uuid}", $body);
}

function uploadSignature(string $submission, string $attachment): TestResponse
{
    $file = UploadedFile::fake()->createWithContent('signature.png', FieldAppScenario::png());

    return test()->withHeaders(test()->headers)->post("/api/v1/submissions/{$submission}/attachments", [
        'file' => $file, 'attachment_uuid' => $attachment, 'kind' => 'signature', 'element_key' => 'customer_signature',
    ]);
}

function submit(string $uuid, int $revision, ?string $key = null): TestResponse
{
    return test()->withHeaders([...test()->headers, 'Idempotency-Key' => $key ?? (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$uuid}/submit", ['revision' => $revision]);
}

it('lists the forms the worker may fill', function (): void {
    $this->withHeaders($this->headers)->getJson('/api/v1/forms')
        ->assertOk()->assertJsonPath('data.0.key', 'service_report')->assertJsonPath('data.0.version.version', 1);
});

it('serves the pinned version definition', function (): void {
    $this->withHeaders($this->headers)->getJson("/api/v1/forms/{$this->s->form->id}/versions/{$this->s->version->id}")
        ->assertOk()->assertJsonPath('data.definition.key', 'service_report')->assertJsonPath('data.checksum', $this->s->version->checksum);
});

it('creates and updates drafts with optimistic revisions', function (): void {
    $uuid = FieldAppScenario::uuid();

    putDraft($uuid, ['customer_name' => 'A'])->assertCreated()->assertJsonPath('data.submission.revision', 1)->assertJsonPath('data.submission.status', 'draft');
    putDraft($uuid, ['customer_name' => 'B'], 1)->assertOk()->assertJsonPath('data.submission.revision', 2);

    putDraft($uuid, ['customer_name' => 'stale'], 1)->assertStatus(409)
        ->assertJsonPath('error.code', 'revision_conflict')
        ->assertJsonPath('error.details.submission.data.customer_name', 'B');
});

it('keeps empty JSON objects as objects', function (): void {
    $uuid = FieldAppScenario::uuid();
    putDraft($uuid, ['items' => [], 'x-extra' => (object) []])->assertCreated();

    $raw = DB::table('submissions')->where('uuid', $uuid)->value('data');
    expect(json_decode($raw, false)->{'x-extra'})->toEqual(new stdClass);
});

it('submits: validates on the server, recomputes totals and assigns gap-free numbers', function (): void {
    $uuid = FieldAppScenario::uuid();
    $sig = FieldAppScenario::uuid();
    putDraft($uuid, [...FieldAppScenario::serviceReportData($sig), 'net_total' => '1.00'])->assertCreated();
    uploadSignature($uuid, $sig)->assertCreated();

    $response = submit($uuid, 1)->assertOk();

    expect($response->json('data.submission.status'))->toBe('submitted')
        ->and($response->json('data.submission.number'))->toBe('SR-'.now('Europe/Berlin')->format('Y').'-00001')
        ->and($response->json('data.submission.data.net_total'))->toBe('195.00')
        ->and($response->json('data.submission.data.gross_total'))->toBe('232.05');

    $second = FieldAppScenario::uuid();
    $sig2 = FieldAppScenario::uuid();
    putDraft($second, FieldAppScenario::serviceReportData($sig2));
    uploadSignature($second, $sig2);
    expect(submit($second, 1)->json('data.submission.number'))->toEndWith('-00002');
});

it('rejects invalid data with JSON-pointer errors and allocates no number', function (): void {
    $uuid = FieldAppScenario::uuid();
    putDraft($uuid, ['customer_name' => 'A', 'items' => [['qty' => 1]]]);

    submit($uuid, 1)->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonFragment(['pointer' => '/items/0/qty', 'code' => 'decimal_string_required']);

    expect(DB::table('number_sequences')->count())->toBe(0);
});

it('requires uploaded attachments for referenced ids', function (): void {
    $uuid = FieldAppScenario::uuid();
    $sig = FieldAppScenario::uuid();
    putDraft($uuid, FieldAppScenario::serviceReportData($sig));

    submit($uuid, 1)->assertUnprocessable()->assertJsonPath('error.code', 'attachments_missing')->assertJsonPath('error.details.0', $sig);
});

it('replays a repeated Idempotency-Key and refuses it for another request', function (): void {
    $uuid = FieldAppScenario::uuid();
    $sig = FieldAppScenario::uuid();
    putDraft($uuid, FieldAppScenario::serviceReportData($sig));
    uploadSignature($uuid, $sig);

    $first = submit($uuid, 1, 'key-12345678')->assertOk();
    $replay = submit($uuid, 1, 'key-12345678')->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json('data.submission.number'))->toBe($first->json('data.submission.number'))
        ->and(Activity::query()->where('event', 'submission.submitted')->count())->toBe(1);
    submit($uuid, 2, 'key-12345678')->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    $this->withoutHeader('Idempotency-Key')->withHeaders($this->headers)->postJson("/api/v1/submissions/{$uuid}/submit", ['revision' => 2])
        ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
});

it('refuses submits on a retired version and after access was revoked', function (): void {
    $retired = FieldAppScenario::uuid();
    putDraft($retired, ['customer_name' => 'A']);
    app(TenantContext::class)->run($this->s->org, fn () => app(RetireVersion::class)->handle($this->s->version, $this->s->owner));
    submit($retired, 1)->assertStatus(409)->assertJsonPath('error.code', 'version_retired');

    $this->s->version->refresh();
    app(TenantContext::class)->run($this->s->org, fn () => app(SyncFormAccess::class)->handle($this->s->form, [], [], $this->s->owner));
    putDraft($retired, ['customer_name' => 'B'], 1)->assertForbidden()->assertJsonPath('error.code', 'form_access_revoked');
    $this->withHeaders($this->headers)->getJson('/api/v1/forms')->assertOk()->assertJsonCount(0, 'data');
});

it('locks drafts after submit and lets only the creator edit', function (): void {
    $uuid = FieldAppScenario::uuid();
    $sig = FieldAppScenario::uuid();
    putDraft($uuid, FieldAppScenario::serviceReportData($sig));
    uploadSignature($uuid, $sig);
    submit($uuid, 1)->assertOk();

    putDraft($uuid, ['customer_name' => 'changed'], 2)->assertStatus(409)->assertJsonPath('error.code', 'not_editable');

    $other = memberOf($this->s->org, SystemRole::FieldWorker);
    $draft = FieldAppScenario::uuid();
    putDraft($draft, ['customer_name' => 'mine']);
    $this->withHeaders(apiHeaders($other, $this->s->org))
        ->putJson("/api/v1/submissions/{$draft}", ['form_version_id' => $this->s->version->id, 'data' => ['customer_name' => 'x'], 'revision' => 1])
        ->assertForbidden();
});

it('writes a status event and audit entry per transition', function (): void {
    $uuid = FieldAppScenario::uuid();
    $sig = FieldAppScenario::uuid();
    putDraft($uuid, FieldAppScenario::serviceReportData($sig));
    uploadSignature($uuid, $sig);
    submit($uuid, 1);

    $submission = app(TenantContext::class)->run($this->s->org, fn () => Submission::query()->findOrFail($uuid));
    expect($submission->events()->pluck('to_status')->all())->toBe(['draft', 'submitted'])
        ->and(Activity::query()->whereIn('event', ['submission.created', 'submission.submitted'])->count())->toBe(2);
});
