<?php

declare(strict_types=1);

use App\Domain\Dashboards\Performance\LoadGenerator;
use App\Domain\Dashboards\Performance\QueryPlan;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/*
 * M8-T05: GET /api/v1/submissions pages an organization's submissions by their last change, newest first
 * (SubmissionController::index). At 100k submissions a sort of all rows took 23 ms for page 1 and 113 ms
 * with a 110 MB on-disk sort for page 100; submissions_org_updated_at_idx lets Postgres read the page
 * backwards from the index and stop at the limit. 2,500 generated submissions, then ANALYZE.
 */

beforeEach(function (): void {
    $this->org = makeOrganization(name: 'Api List GmbH');
    $this->owner = ownerOf($this->org);
    app(LoadGenerator::class)->generate($this->org, D::form($this->org, $this->owner), 2500);
    DB::statement('ANALYZE submissions');
    app(TenantContext::class)->set($this->org);
});

it('has the index for the API submission list', function (): void {
    expect(DB::table('pg_indexes')->where('indexname', 'submissions_org_updated_at_idx')->value('indexdef'))
        ->toContain('(organization_id, updated_at)');
});

it('reads the newest-first API pages from the index instead of sorting every submission', function (int $page): void {
    $query = app(SubmissionVisibility::class)->apply(Submission::query(), $this->owner)
        ->orderByDesc('updated_at')->forPage($page, 50);

    $scans = implode(' | ', QueryPlan::explain($query)->scans());
    $sorts = array_filter(QueryPlan::explain($query)->nodes(), fn (array $node): bool => ($node['Node Type'] ?? '') === 'Sort');

    expect($scans)->toContain('Index Scan using submissions_org_updated_at_idx')->and($sorts)->toBe([]);
})->with([1, 20]);
