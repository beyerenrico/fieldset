<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Events\SubmissionSubmitted;
use Throwable;

/**
 * Queues the rendition after a submit committed (the event fires via DB::afterCommit, LEARNINGS #28).
 * Failures are reported, never thrown: the submit already succeeded and the document can be
 * re-rendered later.
 */
final readonly class QueueRenditionOnSubmit
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(SubmissionSubmitted $event): void
    {
        if (! (bool) config('documents.render_on_submit', true)) {
            return;
        }

        try {
            $this->tenant->run($event->organizationId, function () use ($event): void {
                GenerateRendition::dispatch($event->submissionUuid, $event->organizationId);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
