<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\IdempotencyKey;
use App\Http\Api\ApiResponse;
use App\Http\Api\V1\Middleware\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
});

function idempotentEcho(): void
{
    Route::middleware(['api', 'auth:sanctum', 'organization', IdempotentRequest::class])
        ->post('api/v1/_test/idempotent', fn (): JsonResponse => ApiResponse::ok(['empty' => new stdClass, 'list' => [], 'at' => microtime(true)]));
}

it('does not store error responses: the same key succeeds after the missing attachment was uploaded', function (): void {
    $uuid = FieldAppScenario::uuid();
    $sig = FieldAppScenario::uuid();
    FieldAppApi::put($this->s, $this->s->worker, $uuid, FieldAppScenario::serviceReportData($sig))->assertCreated();

    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'retry-key-0001')
        ->assertUnprocessable()->assertJsonPath('error.code', 'attachments_missing');
    FieldAppApi::signature($this->s, $this->s->worker, $uuid, $sig)->assertCreated();

    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'retry-key-0001')->assertOk()
        ->assertHeaderMissing('Idempotent-Replayed')->assertJsonPath('data.submission.status', 'submitted');
    expect(IdempotencyKey::query()->withoutTenant()->count())->toBe(1);
});

it('refuses a submit after access was revoked and does not replay the refusal once access is back', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    $grant = fn (array $roles) => app(TenantContext::class)->run($this->s->org, fn () => app(SyncFormAccess::class)
        ->handle($this->s->form, [], $roles, $this->s->owner));

    $grant([]);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'revoked-key-01')
        ->assertForbidden()->assertJsonPath('error.code', 'form_access_revoked');
    expect(DB::table('number_sequences')->count())->toBe(0);

    $grant(['field_worker']);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'revoked-key-01')->assertOk()->assertJsonPath('data.submission.status', 'submitted');
});

it('answers a repeated submit of the consumed revision with the current submission (lost response, new key)', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    $first = FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'first-key-0001')->assertOk();

    $retry = FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'second-key-001')->assertOk()
        ->assertHeaderMissing('Idempotent-Replayed')->assertJsonPath('data.submission.status', 'submitted');

    expect($retry->json('data.submission.number'))->toBe($first->json('data.submission.number'))
        ->and($retry->json('data.submission.revision'))->toBe(2)
        ->and(Activity::query()->where('event', 'submission.submitted')->count())->toBe(1)
        ->and(DB::table('submission_events')->where('submission_uuid', $uuid)->where('to_status', 'submitted')->count())->toBe(1);

    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 2, 'third-key-0001')->assertStatus(409)->assertJsonPath('error.code', 'not_editable');
});

it('replays the stored response for a key whose first response was lost', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    $first = FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'lost-response-1')->assertOk();

    $replay = FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1, 'lost-response-1')->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json())->toBe($first->json());
});

it('replays JSON objects as objects', function (): void {
    idempotentEcho();
    $headers = [...apiHeaders($this->s->worker, $this->s->org), 'Idempotency-Key' => 'object-key-001'];

    $first = $this->withHeaders($headers)->postJson('/api/v1/_test/idempotent', [])->assertOk();
    $replay = $this->withHeaders($headers)->postJson('/api/v1/_test/idempotent', [])->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->getContent())->toContain('"empty":{}')->toContain('"list":[]')
        ->and(json_decode((string) $replay->getContent()))->toEqual(json_decode((string) $first->getContent()));
});

it('keeps keys apart per organization for the same user', function (): void {
    idempotentEcho();
    $second = makeOrganization($this->s->owner, 'Second GmbH');
    $call = fn (Organization $org) => $this->withHeaders([...apiHeaders($this->s->owner, $org), 'Idempotency-Key' => 'shared-key-001'])
        ->postJson('/api/v1/_test/idempotent', []);

    $call($this->s->org)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    $call($second)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    $call($second)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(IdempotencyKey::query()->withoutTenant()->where('key', 'shared-key-001')->pluck('organization_id')->sort()->values()->all())
        ->toBe([$this->s->org->id, $second->id]);
});

it('prunes keys after the retention window and schedules the pruning', function (): void {
    app(TenantContext::class)->run($this->s->org, function (): void {
        IdempotencyKey::factory()->create(['user_id' => $this->s->worker->id, 'key' => 'old-key-000001', 'created_at' => now()->subHours(25)]);
        IdempotencyKey::factory()->create(['user_id' => $this->s->worker->id, 'key' => 'new-key-000001', 'created_at' => now()->subHour()]);
    });

    $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

    expect(IdempotencyKey::query()->withoutTenant()->pluck('key')->all())->toBe(['new-key-000001'])
        ->and(config('submissions.idempotency_ttl_hours'))->toBe(24);
    $this->artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
});
