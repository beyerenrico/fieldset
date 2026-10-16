<?php

declare(strict_types=1);

use App\Domain\Dashboards\Performance\DashboardBenchmark;
use App\Domain\Forms\Templates\TemplateCatalog;
use App\Domain\Identity\Enums\SystemRole;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/* M5-T05 measurement tool: `app:dashboard-benchmark {organization}` prints Markdown tables of plans and timings. */

it('measures every catalog query and widget of an organization', function (): void {
    $org = makeOrganization(name: 'Bench GmbH');
    memberOf($org, SystemRole::Reviewer);
    D::form($org, ownerOf($org));
    $this->artisan('app:seed-load', ['organization' => $org->slug, 'count' => 200])->assertSuccessful();

    $this->artisan('app:dashboard-benchmark', ['organization' => $org->slug, '--runs' => 1, '--slice' => 50])
        ->expectsOutputToContain('Organization '.$org->slug.', form service_report')
        ->expectsOutputToContain('| kpi: submissions in range |')
        ->expectsOutputToContain('| report: sum of a number grouped by a choice since a date |')
        ->expectsOutputToContain('| Review queue (reviewer) |')
        ->assertSuccessful();

    $this->artisan('app:dashboard-benchmark', ['organization' => 'missing'])->assertFailed();
});

it('derives lookup keys from the form definition', function (): void {
    expect(DashboardBenchmark::keysFor(TemplateCatalog::bundled()->definition('service_report')))
        ->toBe(['number' => 'net_total', 'date' => 'service_date', 'choice' => 'service_type', 'choice_value' => 'maintenance']);
});
