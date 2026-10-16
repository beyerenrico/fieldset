<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Projection\SubmissionValueProjector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the `submission_values` read model (BUILD_PROMPT §8.4). Each submission is re-read under a
 * row lock and projected in its own transaction, so a concurrent submit or transition never interleaves
 * with its delete + insert. `changes_requested` submissions keep their last submitted values.
 */
#[Description('Rebuild the submission_values read model')]
#[Signature('app:reproject {form? : Form id to limit the rebuild to}')]
final class ReprojectCommand extends Command
{
    public function handle(TenantContext $tenant, SubmissionValueProjector $projector): int
    {
        $formId = $this->argument('form');
        $count = 0;

        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
            $tenant->run($organization, function () use ($projector, $formId, &$count): void {
                Submission::query()->select('uuid')->where('status', '!=', 'draft')
                    ->when(is_string($formId) && $formId !== '', fn ($q) => $q->where('form_id', (int) $formId))
                    ->chunkById(200, function ($submissions) use ($projector, &$count): void {
                        foreach ($submissions as $submission) {
                            $count += $this->reproject($projector, $submission->uuid);
                        }
                    }, 'uuid');
            });
        }

        $this->components->info("Reprojected {$count} submissions.");

        return self::SUCCESS;
    }

    private function reproject(SubmissionValueProjector $projector, string $uuid): int
    {
        return DB::transaction(function () use ($projector, $uuid): int {
            $locked = Submission::query()->whereKey($uuid)->lockForUpdate()->first();
            if ($locked === null || $locked->status === SubmissionStatus::Draft) {
                return 0;
            }
            $projector->project($locked);

            return 1;
        });
    }
}
