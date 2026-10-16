<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Projection\SubmissionValueProjector;
use Illuminate\Console\Command;

/** Rebuilds the `submission_values` read model (BUILD_PROMPT §8.4). */
final class ReprojectCommand extends Command
{
    protected $signature = 'app:reproject {form? : Form id to limit the rebuild to}';

    protected $description = 'Rebuild the submission_values read model';

    public function handle(TenantContext $tenant, SubmissionValueProjector $projector): int
    {
        $formId = $this->argument('form');
        $count = 0;

        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
            $tenant->run($organization, function () use ($projector, $formId, &$count): void {
                Submission::query()->where('status', '!=', 'draft')
                    ->when(is_string($formId) && $formId !== '', fn ($q) => $q->where('form_id', (int) $formId))
                    ->with('formVersion')->chunkById(200, function ($submissions) use ($projector, &$count): void {
                        foreach ($submissions as $submission) {
                            $projector->project($submission);
                            $count++;
                        }
                    }, 'uuid');
            });
        }

        $this->components->info("Reprojected {$count} submissions.");

        return self::SUCCESS;
    }
}
