<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Audit\ErrorText;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Queued rendering of a submission (§8.5, §9.2 "renders the PDF asynchronously"). Carries only ids;
 * the tenant is restored explicitly from the organization id. Gotenberg outages retry with backoff
 * (three exceptions, crashes included, within retryUntil); waiting for an overlapping render of the same
 * submission is a release, not an attempt. A document over the size budget fails at once. The request id
 * travels with the job, so a re-run of the same job (killed worker, re-delivery) never creates a second
 * rendition. A permanent failure is recorded (ADR 0031). The timeout stays below the queue's retry_after.
 */
#[Backoff([15, 60])]
#[Timeout(75)]
#[MaxExceptions(3)]
#[CountCrashesAsExceptions]
final class GenerateRendition implements ShouldQueue
{
    use Queueable;

    /** How long overlapping renders may keep releasing the job before it gives up. */
    public const int RETRY_MINUTES = 30;

    public string $requestId;

    public function __construct(
        public string $submissionUuid,
        public int $organizationId,
        public string $reason = Rendition::REASON_SUBMITTED,
        public ?int $actorId = null,
        ?string $requestId = null,
    ) {
        $this->requestId = $requestId ?? (string) Str::uuid7();
        $this->onQueue((string) config('documents.queue', 'default'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_MINUTES);
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
                $render->handle($submission, $this->reason, $this->actor(), $this->requestId);
            } catch (RenderBudgetExceededException $e) {
                $this->fail($e);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Rendition failed', [
            'submission' => $this->submissionUuid, 'organization' => $this->organizationId, 'reason' => $this->reason,
            'exception' => $exception instanceof Throwable ? $exception::class.': '.ErrorText::scrub(($exception->getPrevious() ?? $exception)->getMessage()) : null,
        ]);

        app(TenantContext::class)->run($this->organizationId, function () use ($exception): void {
            $submission = Submission::query()->find($this->submissionUuid);
            if ($submission !== null) {
                app(RecordRenditionFailure::class)->handle(
                    $submission, $exception ?? new RuntimeException('Rendering failed.'), $this->reason, $this->actor(),
                );
            }
        });
    }

    private function actor(): ?User
    {
        return $this->actorId === null ? null : User::query()->find($this->actorId);
    }
}
