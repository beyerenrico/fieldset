<?php

declare(strict_types=1);

use App\Domain\Dashboards\DateRange;
use App\Domain\Dashboards\Performance\LoadGenerator;
use App\Domain\Dashboards\Performance\QueryCatalog;
use App\Domain\Dashboards\Performance\QueryPlan;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/*
 * M5-T05 / §8.1 AC "widget queries stay index-backed at 100k submissions per organization".
 *
 * Approach: two organizations get generated data (the measured one 2,500 + 1,000 submissions in two forms,
 * ≈ 80k value rows; a neighbour 250), then ANALYZE. Every widget query and every canonical submission_values lookup is
 * EXPLAINed with `SET LOCAL enable_seqscan = off`: the planner then avoids sequential scans whenever an
 * index can serve the predicates, so a remaining Seq Scan — or an index scan without an index condition
 * (a full index walk) — on a large table proves that no usable index exists. Which plan the planner picks
 * on its own depends on table size; the natural plans and timings at 100k submissions are measured with
 * `app:dashboard-benchmark` and recorded in docs/agent/milestones/evidence/M5/performance.md. The
 * selective lookups are additionally asserted index-backed without any planner setting at this scale.
 */

function seedMeasuredOrganizations(): void
{
    $t = test();
    $t->org = makeOrganization(name: 'Measured GmbH');
    $t->owner = ownerOf($t->org);
    $t->reviewer = memberOf($t->org, SystemRole::Reviewer);
    $t->form = D::form($t->org, $t->owner);
    D::in($t->org, function () use ($t): void {
        $group = Group::factory()->create(['share_submissions' => true]);
        app(SyncMemberGroups::class)->handle($t->org, $t->reviewer, [$group->id]);
        DesignVersion::factory()->create();
        Workflow::factory()->create();
    });
    $neighbour = makeOrganization(name: 'Neighbour GmbH');
    $neighbourForm = D::form($neighbour, ownerOf($neighbour));

    $generator = app(LoadGenerator::class);
    $generator->generate($t->org, $t->form, 2500);
    $generator->generate($t->org, D::form($t->org, $t->owner, 'delivery_note'), 1000);
    $generator->generate($neighbour, $neighbourForm, 250);
    DB::statement('ANALYZE '.implode(', ', QueryPlan::LARGE_TABLES));
    app(TenantContext::class)->set($t->org);
}

/** @return array<string, mixed> */
function catalog(): array
{
    $catalog = app(QueryCatalog::class);
    $range = DateRange::lastDays(7, 'Europe/Berlin');
    $keys = ['number' => 'net_total', 'date' => 'service_date', 'choice' => 'service_type', 'choice_value' => 'repair'];

    return [
        ...$catalog->widgets(test()->owner, test()->reviewer, $range),
        ...$catalog->valueLookups(test()->org->id, test()->form->id, $keys, now()->subDays(36)->toDateTimeString()),
    ];
}

/** EXPLAIN with sequential scans disabled for this one statement (SET LOCAL would outlive a released savepoint). */
function planWithoutSeqScans(mixed $query): QueryPlan
{
    DB::statement('SET LOCAL enable_seqscan = off');
    try {
        return QueryPlan::explain($query);
    } finally {
        DB::statement('SET LOCAL enable_seqscan = on');
    }
}

it('backs every widget query and value lookup with an index, and uses the dashboard indexes', function (): void {
    seedMeasuredOrganizations();
    $queries = catalog();

    $violations = [];
    foreach ($queries as $name => $query) {
        $full = planWithoutSeqScans($query)->fullScans();
        if ($full !== []) {
            $violations[$name] = $full;
        }
    }
    $scans = fn (string $name): string => implode(' | ', planWithoutSeqScans($queries[$name])->scans());

    expect($violations)->toBe([])
        ->and($scans('kpi: submissions in range'))->toContain('submissions_org_submitted_at_idx')
        ->and($scans('chart: submissions per form and day'))->toContain('submissions_org_submitted_at_idx')
        ->and($scans('kpi: stale drafts'))->toContain('submissions_org_drafts_updated_at_idx')
        ->and($scans('explorer: page of a form, newest first'))->toContain('submissions_org_form_submitted_at_idx');
});

it('picks index plans on its own for the selective lookups', function (): void {
    seedMeasuredOrganizations();
    $queries = catalog();
    $selective = ['kpi: submissions in range', 'kpi: stale drafts', 'chart: submissions per form and day', 'table: recent submissions (sees all)',
        'table: workflow failures', 'values: number range, newest first', 'explorer: page of a form, newest first'];

    $violations = [];
    foreach ($selective as $name) {
        $full = QueryPlan::explain($queries[$name])->fullScans();
        if ($full !== []) {
            $violations[$name] = $full;
        }
    }

    expect($violations)->toBe([]);
});

it('flags sequential scans and condition-less index walks of large tables', function (): void {
    $plan = planWithoutSeqScans(DB::table('submission_values')->where('value_text', 'x'));

    expect($plan->fullScans())->toBe(['Seq Scan on submission_values'])
        ->and(planWithoutSeqScans(DB::table('submission_values')->orderBy('id')->limit(5))->fullScans())
        ->toBe(['Index Scan on submission_values using submission_values_pkey']);
});
