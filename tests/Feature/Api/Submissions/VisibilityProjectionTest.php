<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\SetFormStatus;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Support\NumberAllocator;
use App\Domain\Submissions\Support\NumberFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
});

function draftFor(User $user, array $data = ['customer_name' => 'A']): string
{
    $uuid = FieldAppScenario::uuid();
    test()->withHeaders(apiHeaders($user, test()->s->org))
        ->putJson("/api/v1/submissions/{$uuid}", ['form_version_id' => test()->s->version->id, 'data' => $data])->assertCreated();

    return $uuid;
}

it('shows workers only their own submissions unless their group shares them', function (): void {
    $peer = memberOf($this->s->org, SystemRole::FieldWorker);
    $mine = draftFor($this->s->worker);
    $theirs = draftFor($peer);

    $list = fn ($user) => $this->withHeaders(apiHeaders($user, $this->s->org))->getJson('/api/v1/submissions')->json('data.*.uuid');
    expect($list($this->s->worker))->toBe([$mine]);

    app(TenantContext::class)->run($this->s->org, function () use ($peer): void {
        $crew = Group::query()->create(['name' => 'Crew', 'share_submissions' => true]);
        foreach ([$this->s->worker, $peer] as $u) {
            DB::table('group_user')->insert(['organization_id' => $this->s->org->id, 'group_id' => $crew->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    expect($list($this->s->worker))->toEqualCanonicalizing([$mine, $theirs])
        ->and($list($this->s->owner))->toEqualCanonicalizing([$mine, $theirs])     // view_all
        ->and($list(memberOf($this->s->org, SystemRole::Viewer)))->toHaveCount(2);
});

it('returns a sync manifest with fillable ids and revocations', function (): void {
    $first = $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson('/api/v1/sync/manifest')->assertOk();
    expect($first->json('data.fillable_form_ids'))->toBe([$this->s->form->id])->and($first->json('data.forms'))->toHaveCount(1);

    $this->travel(1)->seconds();
    app(TenantContext::class)->run($this->s->org, fn () => app(SetFormStatus::class)
        ->handle($this->s->form, FormStatus::Archived, $this->s->owner));

    $second = $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))
        ->getJson('/api/v1/sync/manifest?since='.urlencode($first->json('data.cursor')))->assertOk();
    expect($second->json('data.fillable_form_ids'))->toBe([])->and($second->json('data.revoked_form_ids'))->toBe([$this->s->form->id]);
});

it('projects submitted values including repeater rows and rebuilds them on demand', function (): void {
    $uuid = draftFor($this->s->worker, FieldAppScenario::serviceReportData($sig = FieldAppScenario::uuid(), 3));
    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->post("/api/v1/submissions/{$uuid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('s.png', FieldAppScenario::png()),
        'attachment_uuid' => $sig, 'kind' => 'signature', 'element_key' => 'customer_signature',
    ])->assertCreated();
    $this->withHeaders([...apiHeaders($this->s->worker, $this->s->org), 'Idempotency-Key' => 'k-'.$uuid])
        ->postJson("/api/v1/submissions/{$uuid}/submit", ['revision' => 1])->assertOk();

    $values = DB::table('submission_values')->where('submission_uuid', $uuid);
    expect((clone $values)->where('key', 'items.line_total')->count())->toBe(3)
        ->and((clone $values)->where('path', '/items/2/qty')->value('value_num'))->toBe('1.5000000000')
        ->and((clone $values)->where('key', 'gross_total')->value('role'))->toBe('totals.gross')
        ->and((clone $values)->where('key', 'net_total')->value('value_num'))->toBe('292.5000000000');

    DB::table('submission_values')->delete();
    $this->artisan('app:reproject')->assertSuccessful();
    expect(DB::table('submission_values')->where('submission_uuid', $uuid)->count())->toBeGreaterThan(10);
});

it('formats numbers per period and keeps them gap-free when a submit rolls back', function (): void {
    $at = Carbon::parse('2026-03-05 10:00', 'Europe/Berlin');
    expect(NumberFormat::render('INV-{YY}{MM}-{SEQ:4}', $at, 7))->toBe('INV-2603-0007')
        ->and(NumberFormat::period('monthly', $at))->toBe('2026-03')
        ->and(NumberFormat::period('never', $at))->toBe('all');

    app(TenantContext::class)->set($this->s->org);
    try {
        DB::transaction(function () use ($at): void {
            app(NumberAllocator::class)->allocate($this->s->version, $at);
            throw new RuntimeException('submit failed later');
        });
    } catch (RuntimeException) {
    }
    $number = DB::transaction(fn () => app(NumberAllocator::class)->allocate($this->s->version, $at));

    // (The allocator also refuses to run outside a transaction; tests always run inside RefreshDatabase's.)
    expect($number)->toBe('SR-2026-00001');
});
