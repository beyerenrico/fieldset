<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dashboards\Performance\LoadGenerator;
use App\Domain\Dashboards\Performance\TargetResolver;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Load generator for measurements (M5-T05, §8.1/§8.4 AC at 100k submissions per organization).
 * Inserts generated submissions with values, tasks, renditions (rows only), workflow runs and sent
 * document mails in chunks without events; `--purge` removes every generated row of the organization.
 */
#[Description('Bulk-generate submissions (and related rows) for load measurements, or purge them')]
#[Signature('app:seed-load {organization : Organization slug or id} {count=100000 : Submissions to generate}
    {--form= : Form id or key (default: the first active form)} {--chunk=1000 : Submissions per transaction}
    {--purge : Delete the generated rows of the organization instead} {--force : Run in production}')]
final class SeedLoadCommand extends Command
{
    use ConfirmableTrait;

    private const array ANALYZED = ['submissions', 'submission_values', 'tasks', 'renditions', 'workflow_runs', 'mail_messages'];

    public function handle(LoadGenerator $generator, TenantContext $tenant): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        $organization = TargetResolver::organization($this->argument('organization'));
        if (! $organization instanceof Organization) {
            $this->components->error('Organization not found.');

            return self::FAILURE;
        }
        if ($this->option('purge')) {
            $removed = $generator->purge($organization);
            $this->analyze();
            $this->components->info("Removed {$removed} generated submissions (and their rows) from {$organization->slug}.");

            return self::SUCCESS;
        }

        return $tenant->run($organization, fn (): int => $this->generate($generator, $organization));
    }

    private function generate(LoadGenerator $generator, Organization $organization): int
    {
        $form = TargetResolver::form($this->option('form'));
        $count = max(0, (int) $this->argument('count'));
        if (! $form instanceof Form) {
            $this->components->error('No published form found.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($count);
        $report = $generator->generate($organization, $form, $count, fn (int $done) => $bar->setProgress($done), max(1, (int) $this->option('chunk')));
        $bar->finish();
        $this->newLine(2);
        $this->analyze();

        $rows = [];
        foreach ($report->toArray() as $what => $value) {
            $rows[] = [$what, (string) $value];
        }
        $this->table(['', "{$organization->slug} / {$form->key}"], $rows);

        return self::SUCCESS;
    }

    /** Fresh planner statistics, so measurements right after a load use realistic plans. */
    private function analyze(): void
    {
        DB::statement('ANALYZE '.implode(', ', self::ANALYZED));
    }
}
