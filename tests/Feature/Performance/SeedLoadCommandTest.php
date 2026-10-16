<?php

declare(strict_types=1);

use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Projection\SubmissionValueProjector;
use App\Domain\Workflows\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/* M5-T05 load generator: `app:seed-load {organization} {count} {--form=} {--purge}`. */

beforeEach(function (): void {
    $this->org = makeOrganization(name: 'Load GmbH');
    $this->owner = ownerOf($this->org);
    $this->form = D::form($this->org, $this->owner);
    D::in($this->org, function (): void {
        Group::factory()->create();
        DesignVersion::factory()->create();
        Workflow::factory()->create();
    });
});

function generated(string $table, int $orgId): int
{
    return DB::table($table)->where('organization_id', $orgId)->count();
}

it('generates submissions with values, tasks, renditions, runs and document mails in chunks', function (): void {
    $this->artisan('app:seed-load', ['organization' => $this->org->slug, 'count' => 600, '--chunk' => 250])->assertSuccessful();

    $statuses = DB::table('submissions')->where('organization_id', $this->org->id)->pluck('status')->countBy();
    expect(generated('submissions', $this->org->id))->toBe(600)
        ->and($statuses->keys()->diff(['draft', 'submitted', 'in_review', 'changes_requested', 'approved', 'completed', 'void'])->all())->toBe([])
        ->and($statuses->get('completed'))->toBeGreaterThan(300)
        ->and(generated('submission_values', $this->org->id))->toBeGreaterThan(600 * 10)
        ->and(generated('tasks', $this->org->id))->toBeGreaterThan(0)
        ->and(generated('renditions', $this->org->id))->toBeGreaterThan(0)
        ->and(generated('workflow_runs', $this->org->id))->toBeGreaterThan(0)
        ->and(generated('mail_messages', $this->org->id))->toBeGreaterThan(0)
        ->and(DB::table('submissions')->where('organization_id', $this->org->id)->whereNotNull('number')->distinct()->count('number'))
        ->toBe(600 - (int) $statuses->get('draft', 0))
        ->and(DB::table('submission_values as v')->join('submissions as s', 's.uuid', '=', 'v.submission_uuid')->where('s.status', 'draft')->count())->toBe(0)
        ->and(DB::table('tasks')->where('organization_id', $this->org->id)->where('status', 'open')->count())
        ->toBe((int) $statuses->get('in_review', 0));
});

it('projects exactly what the SubmissionValueProjector would write', function (): void {
    $this->artisan('app:seed-load', ['organization' => (string) $this->org->id, 'count' => 40])->assertSuccessful();
    $rows = fn (string $uuid): array => DB::table('submission_values')->where('submission_uuid', $uuid)->orderBy('path')
        ->get(['key', 'path', 'role', 'value_text', 'value_num', 'value_date', 'status', 'submitted_at', 'form_version_id'])
        ->map(fn ($row): array => (array) $row)->all();

    app(TenantContext::class)->run($this->org, function () use ($rows): void {
        $sample = Submission::query()->where('status', '!=', 'draft')->with('formVersion')->limit(5)->get();
        expect($sample)->not->toBeEmpty();
        foreach ($sample as $submission) {
            $generated = $rows($submission->uuid);
            app(SubmissionValueProjector::class)->project($submission);
            expect($rows($submission->uuid))->toBe($generated);
        }
    });
});

it('purges only the generated rows of the organization', function (): void {
    $manual = D::submission($this->form, $this->owner, 'submitted', now(), 'SR-MANUAL');
    $other = makeOrganization(name: 'Other GmbH');
    $otherForm = D::form($other, ownerOf($other));
    $this->artisan('app:seed-load', ['organization' => $this->org->slug, 'count' => 120])->assertSuccessful();
    $this->artisan('app:seed-load', ['organization' => $other->slug, 'count' => 30, '--form' => $otherForm->key])->assertSuccessful();

    $this->artisan('app:seed-load', ['organization' => $this->org->slug, '--purge' => true])
        ->expectsOutputToContain('Removed 120 generated submissions')->assertSuccessful();

    expect(DB::table('submissions')->where('organization_id', $this->org->id)->pluck('uuid')->all())->toBe([$manual->uuid])
        ->and(generated('renditions', $this->org->id) + generated('tasks', $this->org->id) + generated('workflow_runs', $this->org->id)
            + generated('mail_messages', $this->org->id) + generated('submission_values', $this->org->id))->toBe(0)
        ->and(generated('submissions', $other->id))->toBe(30);
});

it('generates into the given form only and refuses unknown organizations and forms', function (): void {
    $note = D::form($this->org, $this->owner, 'delivery_note');

    $this->artisan('app:seed-load', ['organization' => $this->org->slug, 'count' => 25, '--form' => $note->key])->assertSuccessful();
    expect(DB::table('submissions')->where('organization_id', $this->org->id)->distinct()->pluck('form_id')->all())->toBe([$note->id]);

    $this->artisan('app:seed-load', ['organization' => 'nope', 'count' => 1])->assertFailed();
    $this->artisan('app:seed-load', ['organization' => $this->org->slug, 'count' => 1, '--form' => 'missing'])->assertFailed();
});
