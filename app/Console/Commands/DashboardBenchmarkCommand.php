<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dashboards\Performance\DashboardBenchmark;
use App\Domain\Dashboards\Performance\TargetResolver;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Measures the dashboard widget queries and the canonical submission_values lookups of an organization
 * (M5-T05): EXPLAIN ANALYZE medians, scans used, full scans of large tables, and uncached widget timings.
 * Prints Markdown tables for docs/agent/milestones/evidence/M5/performance.md.
 */
#[Description('Measure dashboard widget queries and submission_values lookups (EXPLAIN ANALYZE)')]
#[Signature('app:dashboard-benchmark {organization : Organization slug or id} {--form= : Form id or key (default: the first active form)}
    {--runs=5 : Measured runs per query} {--slice=10000 : Submissions in the aggregate slice}')]
final class DashboardBenchmarkCommand extends Command
{
    public function handle(DashboardBenchmark $benchmark, TenantContext $tenant): int
    {
        $organization = TargetResolver::organization($this->argument('organization'));
        if (! $organization instanceof Organization) {
            $this->components->error('Organization not found.');

            return self::FAILURE;
        }

        return $tenant->run($organization, fn (): int => $this->measure($benchmark, $organization));
    }

    private function measure(DashboardBenchmark $benchmark, Organization $organization): int
    {
        $form = TargetResolver::form($this->option('form'));
        $version = $form instanceof Form ? FormVersion::query()->find($form->current_version_id) : null;
        if (! $form instanceof Form || ! $version instanceof FormVersion) {
            $this->components->error('No published form found.');

            return self::FAILURE;
        }

        $manager = $this->member($organization, 'owner');
        $reviewer = $this->member($organization, 'reviewer') ?? $manager;
        if (! $manager instanceof User || ! $reviewer instanceof User) {
            $this->components->error('The organization has no owner.');

            return self::FAILURE;
        }
        $runs = max(1, (int) $this->option('runs'));
        $slice = max(1, (int) $this->option('slice'));
        $since = DashboardBenchmark::sliceSince($form, $slice);
        $keys = DashboardBenchmark::keysFor($version->definition);

        $this->line(sprintf('Organization %s, form %s; %s; aggregate slice: %d newest submissions (submitted_at >= %s); keys %s; median of %d runs.',
            $organization->slug, $form->key, $this->volumes($organization->id), $slice, $since, json_encode($keys), $runs));
        $this->newLine();
        $this->line('| Query | Median ms | Rows | Scans | Full scans of large tables |');
        $this->line('|---|---:|---:|---|---|');
        foreach ($benchmark->queries($manager, $reviewer, $form, $keys, $since, $runs) as $r) {
            $this->line("| {$r['name']} | {$r['ms']} | {$r['rows']} | {$r['scans']} | ".($r['full_scans'] ?: 'none').' |');
        }
        $this->newLine();
        $this->line('| Widget data (uncached, PHP) | Median ms |');
        $this->line('|---|---:|');
        foreach ($benchmark->widgets($manager, $reviewer, $organization->id, $runs) as $name => $ms) {
            $this->line("| {$name} | {$ms} |");
        }

        return self::SUCCESS;
    }

    private function member(Organization $organization, string $role): ?User
    {
        $id = DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.organization_id', $organization->id)->where('r.name', $role)->where('m.model_type', (new User)->getMorphClass())
            ->orderBy('m.model_id')->value('m.model_id');

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }

    private function volumes(int $organizationId): string
    {
        $counts = [];
        foreach (['submissions', 'submission_values', 'tasks', 'renditions', 'workflow_runs', 'mail_messages'] as $table) {
            $counts[] = $table.' '.DB::table($table)->where('organization_id', $organizationId)->count();
        }

        return implode(', ', $counts);
    }
}
