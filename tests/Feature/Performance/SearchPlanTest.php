<?php

declare(strict_types=1);

use App\Domain\Dashboards\Performance\LoadGenerator;
use App\Domain\Dashboards\Performance\QueryPlan;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Filters\ReportScope;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/*
 * Explorer search (ADR 0033): "any value contains the term" is `value_text ILIKE '%term%'` on the form's
 * submission_values. Without an index that reads every value row of the form (392 ms at 100k submissions);
 * the GIN trigram index (pg_trgm) answers it from the index instead. 2,500 generated submissions
 * (≈ 55k value rows), one carrying a rare word, then ANALYZE; the planner picks the trigram index on its own.
 */

beforeEach(function (): void {
    $this->org = makeOrganization(name: 'Search GmbH');
    $this->owner = ownerOf($this->org);
    $this->form = D::form($this->org, $this->owner);
    app(LoadGenerator::class)->generate($this->org, $this->form, 2500);
    $text = DB::table('submission_values')->where('form_id', $this->form->id)->whereNull('value_num')->whereNull('value_date')
        ->where('value_text', 'not like', '[%')->orderBy('id')->first(['id', 'submission_uuid']);
    DB::table('submission_values')->where('id', $text->id)->update(['value_text' => 'Zyxquorp Kältetechnik GmbH']);
    $this->needle = $text->submission_uuid;
    DB::statement('ANALYZE submission_values, submissions');
    app(TenantContext::class)->set($this->org);
});

it('has the trigram index on submission_values.value_text', function (): void {
    $definition = DB::table('pg_indexes')->where('indexname', 'submission_values_value_text_trgm_idx')->value('indexdef');

    expect($definition)->toContain('USING gin (value_text gin_trgm_ops)');
});

it('finds a rare word through the trigram index instead of scanning the form\'s values', function (): void {
    $query = app(ReportScope::class)->submissions($this->owner, $this->form, new ReportFilters(search: 'quorp kälte'), 'Europe/Berlin');

    $plan = QueryPlan::explain($query);

    expect($query->pluck('submissions.uuid')->all())->toBe([$this->needle])
        ->and(implode(' | ', $plan->scans()))->toContain('Bitmap Index Scan using submission_values_value_text_trgm_idx')
        ->and(array_filter($plan->fullScans(), fn (string $scan): bool => str_contains($scan, 'submission_values')))->toBe([]);
});
