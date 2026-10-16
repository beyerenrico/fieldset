<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\RetireVersion;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
});

function publishSecondVersion(): FormVersion
{
    $s = test()->s;

    return app(TenantContext::class)->run($s->org, fn (): FormVersion => app(PublishForm::class)->handle($s->form, 'v2', $s->owner));
}

it('answers a PUT retried after a lost response with the current draft instead of a conflict', function (): void {
    $uuid = FieldAppScenario::uuid();
    $put = fn (array $data, ?int $revision = null): TestResponse => FieldAppApi::put($this->s, $this->s->worker, $uuid, $data, $revision);

    $put(['customer_name' => 'A'])->assertCreated();
    $put(['customer_name' => 'A'])->assertOk()->assertJsonPath('data.submission.revision', 1);          // create retried
    $put(['customer_name' => 'B'], 1)->assertOk()->assertJsonPath('data.submission.revision', 2);
    $put(['customer_name' => 'B'], 1)->assertOk()->assertJsonPath('data.submission.revision', 2);       // update retried

    $put(['customer_name' => 'C'], 1)->assertStatus(409)->assertJsonPath('error.code', 'revision_conflict');
    $put(['customer_name' => 'X'])->assertStatus(409)->assertJsonPath('error.code', 'revision_conflict');
});

it('treats a concurrent create of the same uuid like a sequential retry instead of failing', function (): void {
    $uuid = FieldAppScenario::uuid();
    $s = $this->s;
    $raced = false;
    // Another request inserts the same uuid after our lookup, right before our insert.
    Event::listen('eloquent.retrieved: '.FormVersion::class, function () use (&$raced, $uuid, $s): void {
        if ($raced) {
            return;
        }
        $raced = true;
        DB::table('submissions')->insert([
            'uuid' => $uuid, 'organization_id' => $s->org->id, 'form_id' => $s->form->id, 'form_version_id' => $s->version->id,
            'status' => 'draft', 'data' => '{"customer_name":"A"}', 'revision' => 1, 'created_by' => $s->worker->id,
            'updated_by' => $s->worker->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    FieldAppApi::put($s, $s->worker, $uuid, ['customer_name' => 'A'])->assertOk()->assertJsonPath('data.submission.revision', 1);
    expect($raced)->toBeTrue();
});

it('rejects a form_version_id that differs from the pinned version', function (): void {
    $uuid = FieldAppScenario::uuid();
    FieldAppApi::put($this->s, $this->s->worker, $uuid, ['customer_name' => 'A'])->assertCreated();
    $v2 = publishSecondVersion();

    FieldAppApi::put($this->s, $this->s->worker, $uuid, ['customer_name' => 'B'], 1, $v2->id)
        ->assertStatus(409)->assertJsonPath('error.code', 'version_mismatch');
    expect(DB::table('submissions')->where('uuid', $uuid)->value('form_version_id'))->toBe($this->s->version->id);
});

it('refuses to create a draft on a retired version', function (): void {
    $v2 = publishSecondVersion();
    app(TenantContext::class)->run($this->s->org, fn () => app(RetireVersion::class)->handle($this->s->version, $this->s->owner));

    FieldAppApi::put($this->s, $this->s->worker, FieldAppScenario::uuid(), ['customer_name' => 'A'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_retired');
    FieldAppApi::put($this->s, $this->s->worker, FieldAppScenario::uuid(), ['customer_name' => 'A'], null, $v2->id)->assertCreated();
    expect(DB::table('submissions')->count())->toBe(1);
});

it('hides drafts of other users the caller cannot see (404) and refuses visible ones (403)', function (): void {
    $peer = memberOf($this->s->org, SystemRole::FieldWorker);
    $uuid = FieldAppScenario::uuid();
    FieldAppApi::put($this->s, $this->s->worker, $uuid, ['customer_name' => 'mine'])->assertCreated();

    FieldAppApi::put($this->s, $peer, $uuid, ['customer_name' => 'x'], 1)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    app(TenantContext::class)->run($this->s->org, function () use ($peer): void {
        $crew = Group::query()->create(['name' => 'Crew', 'share_submissions' => true]);
        foreach ([$this->s->worker, $peer] as $u) {
            DB::table('group_user')->insert(['organization_id' => $this->s->org->id, 'group_id' => $crew->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    FieldAppApi::put($this->s, $peer, $uuid, ['customer_name' => 'x'], 1)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});

it('caps the request body before decoding it', function (): void {
    expect(config('submissions.max_payload_bytes'))->toBe(2 * 1024 * 1024);
    config(['submissions.max_payload_bytes' => 4096]);
    $uuid = FieldAppScenario::uuid();

    FieldAppApi::put($this->s, $this->s->worker, $uuid, ['customer_name' => str_repeat('x', 5000)])
        ->assertStatus(413)->assertJsonPath('success', false)->assertJsonPath('error.code', 'payload_too_large');

    $this->call('PUT', "/api/v1/submissions/{$uuid}", [], [], [], [
        'HTTP_X_ORGANIZATION_ID' => (string) $this->s->org->id, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'text/plain',
    ], str_repeat('{', 5000))->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');

    FieldAppApi::put($this->s, $this->s->worker, $uuid, ['customer_name' => 'small'])->assertCreated();
});
