<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Support\Carbon;

/**
 * Widget data as plain, cacheable arrays (DashboardCache). Organization-wide figures are shared by all
 * viewers of the organization; submission lists follow the viewer's visibility (§8.6).
 */
final readonly class DashboardData
{
    public function __construct(private DashboardQueries $queries, private DashboardCache $cache, private SubmissionsChart $chart) {}

    /** @return array{submissions: int, pending_reviews: int, stale_drafts: int, documents_sent: int} */
    public function kpis(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'kpis', CacheScope::Organization, fn (): array => [
            'submissions' => $this->queries->submittedInRange($context->range)->count(),
            'pending_reviews' => $this->queries->pendingReviews()->count(),
            'stale_drafts' => $this->queries->staleDrafts(self::staleDraftDays())->count(),
            'documents_sent' => $this->queries->documentsSent($context->range)->count(),
        ]);
    }

    /** @return array{labels: list<string>, series: list<array{label: ?string, data: list<int>}>} */
    public function submissionsChart(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'submissions_chart', CacheScope::Organization, fn (): array => $this->chart->build(
            $context->range,
            array_values($this->queries->submissionsPerFormPerDay($context->range)->toBase()->get()->all()),
        ));
    }

    /** @return list<array{id: int, workflow_id: int, workflow: string, error: ?string, finished_at: ?string}> */
    public function workflowFailures(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'workflow_failures', CacheScope::Organization, fn (): array => array_values($this->queries
            ->workflowFailures($context->range, $this->limit())->with('workflow:id,name')->get()
            ->map(fn (WorkflowRun $run): array => [
                'id' => $run->id, 'workflow_id' => $run->workflow_id, 'workflow' => $run->workflow->name,
                'error' => $run->error === null ? null : mb_strimwidth($run->error, 0, 160, '…'),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->all()));
    }

    /** @return list<array{id: int, submission_uuid: string, number: ?string, form: string, due_at: ?string, overdue: bool, created_at: string}> */
    public function reviewQueue(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'review_queue', CacheScope::User, fn (): array => array_values($this->queries
            ->reviewQueue($context->user, $this->limit())->with('submission:uuid,form_id,number', 'submission.form:id,name')->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id, 'submission_uuid' => $task->submission_uuid, 'number' => $task->submission->number,
                'form' => $task->submission->form->name, 'due_at' => $task->due_at?->toIso8601String(),
                'overdue' => $task->due_at instanceof Carbon && $task->due_at->isPast(), 'created_at' => $task->created_at->toIso8601String(),
            ])->all()));
    }

    /** @return list<array{uuid: string, number: ?string, form: string, status: string, submitted_at: ?string, submitter: ?string}> */
    public function recentSubmissions(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'recent_submissions', CacheScope::Visibility, fn (): array => array_values($this->queries
            ->recentSubmissions($context->user, $context->range, $this->limit())->with('form:id,name', 'creator:id,name')->get()
            ->map(fn (Submission $s): array => [
                'uuid' => $s->uuid, 'number' => $s->number, 'form' => $s->form->name, 'status' => $s->status->value,
                'submitted_at' => $s->submitted_at?->toIso8601String(), 'submitter' => $s->creator?->name,
            ])->all()));
    }

    /** @return list<array{id: int, name: string, category: ?string, updated_at: string}> */
    public function draftForms(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'draft_forms', CacheScope::Organization, fn (): array => array_values($this->queries
            ->draftForms($this->limit())->get(['id', 'name', 'category', 'updated_at'])
            ->map(fn (Form $form): array => [
                'id' => $form->id, 'name' => $form->name, 'category' => $form->category, 'updated_at' => $form->updated_at?->toIso8601String() ?? '',
            ])->all()));
    }

    /** @return list<array{id: int, form_id: int, form: string, version: int, published_at: string, publisher: ?string}> */
    public function recentlyPublished(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'recently_published', CacheScope::Organization, fn (): array => array_values($this->queries
            ->recentlyPublished($context->range, $this->limit())->with('form:id,name', 'publisher:id,name')->get()
            ->map(fn (FormVersion $version): array => [
                'id' => $version->id, 'form_id' => $version->form_id, 'form' => $version->form->name, 'version' => $version->version,
                'published_at' => $version->published_at->toIso8601String(), 'publisher' => $version->publisher?->name,
            ])->all()));
    }

    public static function staleDraftDays(): int
    {
        return max(1, (int) config('dashboards.stale_draft_days', 14));
    }

    private function limit(): int
    {
        return max(1, (int) config('dashboards.table_limit', 10));
    }
}
