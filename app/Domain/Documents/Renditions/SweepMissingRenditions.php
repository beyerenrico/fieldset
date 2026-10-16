<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Safety net for automatic rendering (ADR 0031): re-dispatches GenerateRendition for submitted (non-draft,
 * non-void) submissions that have no rendition since their latest submit and no recorded permanent failure,
 * once they are older than documents.sweep.after_minutes (a lost dispatch, a queue outage, a job that was
 * dropped). A cool-down per submission keeps a slow queue from being flooded; the job itself is idempotent.
 * Platform code: runs without a tenant and dispatches each job inside its organization.
 */
final readonly class SweepMissingRenditions
{
    public function __construct(private TenantContext $tenant) {}

    /** @return int number of dispatched renders */
    public function handle(): int
    {
        if (! (bool) config('documents.render_on_submit', true)) {
            return 0;
        }
        $after = max(1, (int) config('documents.sweep.after_minutes', 10));
        $dispatched = 0;

        foreach ($this->candidates($after) as $submission) {
            if (! Cache::add('documents:sweep:'.$submission->uuid, true, now()->addMinutes($after))) {
                continue;
            }
            $this->tenant->run($submission->organization_id, function () use ($submission): void {
                GenerateRendition::dispatch($submission->uuid, $submission->organization_id);
            });
            $dispatched++;
        }

        return $dispatched;
    }

    /** @return iterable<Submission> */
    private function candidates(int $afterMinutes): iterable
    {
        return Submission::query()->withoutTenant()
            ->whereNotIn('status', [SubmissionStatus::Draft->value, SubmissionStatus::Void->value])
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<=', now()->subMinutes($afterMinutes))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('renditions')
                ->whereColumn('renditions.submission_uuid', 'submissions.uuid')
                ->whereColumn('renditions.created_at', '>=', 'submissions.submitted_at'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('rendition_failures')
                ->whereColumn('rendition_failures.submission_uuid', 'submissions.uuid'))
            ->orderBy('submitted_at')
            ->limit(max(1, (int) config('documents.sweep.batch', 100)))
            ->get(['uuid', 'organization_id']);
    }
}
