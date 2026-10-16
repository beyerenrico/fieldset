<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\WorkflowRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Widget data as plain, cacheable arrays (DashboardCache). Organization-wide figures are shared by all
 * viewers of the organization; submission counts and lists follow the viewer's visibility (§8.6) and
 * count like reports (no drafts, no void). computedAt() tells a widget how fresh its cached data is.
 */
final readonly class DashboardData
{
    /** Cache key => who shares the cached value. */
    public const array SCOPES = [
        'kpis' => CacheScope::Visibility,
        'submissions_chart' => CacheScope::Visibility,
        'workflow_failures' => CacheScope::Organization,
        'review_queue' => CacheScope::User,
        'recent_submissions' => CacheScope::Visibility,
        'draft_forms' => CacheScope::Organization,
        'recently_published' => CacheScope::Organization,
    ];

    public function __construct(private DashboardQueries $queries, private DashboardCache $cache, private SubmissionsChart $chart) {}

    /** When the widget's data (loaded from the cache or computed now) was computed. */
    public function computedAt(DashboardContext $context, string $widget): ?CarbonImmutable
    {
        match ($widget) {
            'kpis' => $this->kpis($context),
            'submissions_chart' => $this->submissionsChart($context),
            'workflow_failures' => $this->workflowFailures($context),
            'review_queue' => $this->reviewQueue($context),
            'recent_submissions' => $this->recentSubmissions($context),
            'draft_forms' => $this->draftForms($context),
            'recently_published' => $this->recentlyPublished($context),
            default => null,
        };

        return $this->cache->computedAt($context, $widget, self::SCOPES[$widget] ?? CacheScope::User);
    }

    /**
     * KPIs (§8.1 Owner): submissions in the range the viewer may see without drafts and void (like
     * reports), open review tasks, drafts untouched for stale_draft_days, documents sent in the range.
     *
     * @return array{submissions: int, pending_reviews: int, stale_drafts: int, documents_sent: int}
     */
    public function kpis(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'kpis', self::SCOPES['kpis'], fn (): array => [
            'submissions' => $this->queries->reportableInRange($context->user, $context->range)->count(),
            'pending_reviews' => $this->queries->pendingReviews()->count(),
            'stale_drafts' => $this->queries->staleDrafts(self::staleDraftDays())->count(),
            'documents_sent' => $this->queries->documentsSent($context->range)->count(),
        ]);
    }

    /** @return array{labels: list<string>, series: list<array{label: ?string, data: list<int>}>} */
    public function submissionsChart(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'submissions_chart', self::SCOPES['submissions_chart'], fn (): array => $this->chart->build(
            $context->range,
            array_values($this->queries->submissionsPerFormPerDay($context->user, $context->range)->toBase()->get()->all()),
        ));
    }

    /** @return list<array{id: int, workflow_id: int, workflow: string, error: ?string, finished_at: ?string}> */
    public function workflowFailures(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'workflow_failures', self::SCOPES['workflow_failures'], fn (): array => array_values($this->queries
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
        return $this->cache->remember($context, 'review_queue', self::SCOPES['review_queue'], fn (): array => array_values($this->queries
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
        return $this->cache->remember($context, 'recent_submissions', self::SCOPES['recent_submissions'], fn (): array => array_values($this->queries
            ->recentSubmissions($context->user, $context->range, $this->limit())->with('form:id,name', 'creator:id,name')->get()
            ->map(fn (Submission $s): array => [
                'uuid' => $s->uuid, 'number' => $s->number, 'form' => $s->form->name, 'status' => $s->status->value,
                'submitted_at' => $s->submitted_at?->toIso8601String(), 'submitter' => $s->creator?->name,
            ])->all()));
    }

    /** @return list<array{id: int, name: string, category: ?string, updated_at: string}> */
    public function draftForms(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'draft_forms', self::SCOPES['draft_forms'], fn (): array => array_values($this->queries
            ->draftForms($this->limit())->get(['id', 'name', 'category', 'updated_at'])
            ->map(fn (Form $form): array => [
                'id' => $form->id, 'name' => $form->name, 'category' => $form->category, 'updated_at' => $form->updated_at?->toIso8601String() ?? '',
            ])->all()));
    }

    /** @return list<array{id: int, form_id: int, form: string, version: int, published_at: string, publisher: ?string}> */
    public function recentlyPublished(DashboardContext $context): array
    {
        return $this->cache->remember($context, 'recently_published', self::SCOPES['recently_published'], fn (): array => array_values($this->queries
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
