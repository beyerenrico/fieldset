<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/*
 * M6 (ADR 0035 "Backend", QUESTIONS #14): submit accepts the device's `client_submitted_at`. It is stored
 * and shown; number, period and submitted_at stay on server time.
 */

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
    Carbon::setTestNow('2026-10-17 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param  array<string, mixed>  $extra */
function m6Submit(string $uuid, int $revision, array $extra = [], ?string $key = null): TestResponse
{
    $s = test()->s;

    return test()->withHeaders([...apiHeaders($s->worker, $s->org), 'Idempotency-Key' => $key ?? (string) Str::uuid()])
        ->postJson("/api/v1/submissions/{$uuid}/submit", ['revision' => $revision, ...$extra]);
}

function m6Stored(string $uuid): Submission
{
    return app(TenantContext::class)->run(test()->s->org, fn (): Submission => Submission::query()->findOrFail($uuid));
}

it('stores and returns client_submitted_at while number and submitted_at stay on server time', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);

    $response = m6Submit($uuid, 1, ['client_submitted_at' => '2026-10-16T23:30:00.123Z'])->assertOk();

    expect($response->json('data.submission.client_submitted_at'))->toBe('2026-10-16T23:30:00+00:00')
        ->and($response->json('data.submission.submitted_at'))->toBe('2026-10-17T10:00:00+00:00')
        ->and($response->json('data.submission.number'))->toContain('2026')
        ->and(m6Stored($uuid)->client_submitted_at?->toIso8601String())->toBe('2026-10-16T23:30:00+00:00');
});

it('accepts an offset and keeps the instant', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);

    m6Submit($uuid, 1, ['client_submitted_at' => '2026-10-17T11:58:00+02:00'])->assertOk()
        ->assertJsonPath('data.submission.client_submitted_at', '2026-10-17T09:58:00+00:00');
});

it('leaves client_submitted_at null when the client does not send it', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);

    m6Submit($uuid, 1)->assertOk()->assertJsonPath('data.submission.client_submitted_at', null);
    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson("/api/v1/submissions/{$uuid}")
        ->assertOk()->assertJsonPath('data.submission.client_submitted_at', null);
});

it('accepts a client clock up to five minutes ahead and refuses more', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);

    m6Submit($uuid, 1, ['client_submitted_at' => '2026-10-17T10:05:01Z'])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.0.pointer', '/client_submitted_at')
        ->assertJsonPath('error.details.0.code', 'before_or_equal');
    m6Submit($uuid, 1, ['client_submitted_at' => '2026-10-17T10:05:00Z'])->assertOk();
});

it('refuses implausibly old device times that the database cannot store (M6 review)', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);

    m6Submit($uuid, 1, ['client_submitted_at' => '0001-01-01T00:00+14:00'])->assertUnprocessable()
        ->assertJsonPath('error.details.0.pointer', '/client_submitted_at')
        ->assertJsonPath('error.details.0.code', 'after_or_equal');
    m6Submit($uuid, 1, ['client_submitted_at' => '2000-01-01T00:00:00Z'])->assertOk();
});

it('refuses values that are not ISO 8601 date-times with an offset', function (mixed $value): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);

    m6Submit($uuid, 1, ['client_submitted_at' => $value])->assertUnprocessable()
        ->assertJsonPath('error.details.0.pointer', '/client_submitted_at');
    expect(m6Stored($uuid)->status->value)->toBe('draft');
})->with([
    'no offset' => '2026-10-17T09:00:00',
    'date only' => '2026-10-17',
    'relative' => 'yesterday',
    'impossible date' => '2026-02-30T09:00:00Z',
    'number' => 1760000000,
]);

it('keeps the stored value when a lost submit response is repeated', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    m6Submit($uuid, 1, ['client_submitted_at' => '2026-10-17T09:00:00Z'], 'submit-key-000001')->assertOk();

    m6Submit($uuid, 1, ['client_submitted_at' => '2026-10-17T09:59:00Z'])->assertOk()
        ->assertJsonPath('data.submission.client_submitted_at', '2026-10-17T09:00:00+00:00');
    m6Submit($uuid, 1, ['client_submitted_at' => '2026-10-17T09:00:00Z'], 'submit-key-000001')->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');
});
