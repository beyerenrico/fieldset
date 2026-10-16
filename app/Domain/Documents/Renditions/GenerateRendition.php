<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued rendering of a submission (§8.5, §9.2 "renders the PDF asynchronously"). Carries only ids;
 * the tenant is restored explicitly from the organization id. Gotenberg outages retry with backoff;
 * a document over the size budget fails at once (retrying cannot help). The timeout stays below the
 * queue's retry_after (90 s) and renders of one submission never overlap.
 */
#[Backoff([15, 60])]
#[Timeout(75)]
#[Tries(3)]
final class GenerateRendition implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $submissionUuid,
        public int $organizationId,
        public string $reason = Rendition::REASON_SUBMITTED,
        public ?int $actorId = null,
    ) {
        $this->onQueue((string) config('documents.queue', 'default'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping('rendition:'.$this->submissionUuid)->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(RenderSubmission $render, TenantContext $tenant): void
    {
        $tenant->run($this->organizationId, function () use ($render): void {
            $submission = Submission::query()->find($this->submissionUuid);
            if ($submission === null || $submission->status === SubmissionStatus::Draft) {
                return;
            }

            try {
                $render->handle($submission, $this->reason, $this->actorId === null ? null : User::query()->find($this->actorId));
            } catch (RenderBudgetExceededException $e) {
                $this->fail($e);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Rendition failed', [
            'submission' => $this->submissionUuid, 'organization' => $this->organizationId, 'reason' => $this->reason,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
