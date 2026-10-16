<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Tasks\TaskAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The widget queries (§8.1). Every query starts from a tenant-owned model, so the OrganizationScope
 * restricts it to the current organization (and throws without one). Each one is backed by an index
 * whose first column is organization_id (EXPLAIN tests in tests/Feature/Performance).
 */
final readonly class DashboardQueries
{
    public function __construct(private SubmissionVisibility $visibility, private TaskAccess $tasks) {}

    /** @return Builder<Submission> submissions submitted in the range (index: organization_id, submitted_at) */
    public function submittedInRange(DateRange $range): Builder
    {
        return Submission::query()
            ->where('submitted_at', '>=', $range->startUtc())
            ->where('submitted_at', '<', $range->endUtc());
    }

    /** @return Builder<Task> open review tasks (index: organization_id, status, due_at) */
    public function pendingReviews(): Builder
    {
        return Task::query()->where('status', TaskStatus::Open->value)->where('type', 'review');
    }

    /** @return Builder<Submission> drafts not updated for $days days (partial index on drafts) */
    public function staleDrafts(int $days): Builder
    {
        return Submission::query()
            ->where('status', SubmissionStatus::Draft->value)
            ->where('updated_at', '<', now()->subDays($days));
    }

    /**
     * Mails about a submission sent with the organization's branded template (send to customer and
     * workflow `send_email`), queued in the range. Notification mails are not documents.
     *
     * @return Builder<MailMessage> (index: organization_id, status, created_at)
     */
    public function documentsSent(DateRange $range): Builder
    {
        return MailMessage::query()
            ->where('status', MailStatus::Sent->value)
            ->where('created_at', '>=', $range->startUtc())
            ->where('created_at', '<', $range->endUtc())
            ->where('mailable_class', BrandedMail::class)
            ->whereNotNull('submission_uuid');
    }

    /**
     * Rows (form_id, day, total) per form and day in the viewer's time zone. `submitted_at` is a UTC
     * timestamp without time zone, hence the double conversion; GROUP BY uses the output alias so the
     * time zone is bound once.
     *
     * @return Builder<Submission>
     */
    public function submissionsPerFormPerDay(DateRange $range): Builder
    {
        return $this->submittedInRange($range)
            ->select('form_id')
            ->selectRaw("date(timezone(?, timezone('UTC', submitted_at))) as day", [$range->timezone])
            ->selectRaw('count(*) as total')
            ->groupBy('form_id', 'day');
    }

    /** @return Builder<WorkflowRun> failed runs finished in the range, newest first (index: organization_id, status) */
    public function workflowFailures(DateRange $range, int $limit): Builder
    {
        return WorkflowRun::query()
            ->where('status', RunStatus::Failed->value)
            ->where('finished_at', '>=', $range->startUtc())
            ->where('finished_at', '<', $range->endUtc())
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->limit($limit);
    }

    /** @return Builder<Task> open tasks the user may act on (TaskAccess::actionable), most urgent first */
    public function reviewQueue(User $user, int $limit): Builder
    {
        return $this->tasks->actionable(Task::query(), $user)
            ->where('status', TaskStatus::Open->value)
            ->orderByRaw('due_at asc nulls last')->orderBy('id')
            ->limit($limit);
    }

    /** @return Builder<Submission> submissions the user may see (§8.6), submitted in the range, newest first */
    public function recentSubmissions(User $user, DateRange $range, int $limit): Builder
    {
        return $this->visibility->apply($this->submittedInRange($range), $user)
            ->orderByDesc('submitted_at')
            ->limit($limit);
    }

    /** @return Builder<Form> forms never published, last edited first (index: organization_id, status) */
    public function draftForms(int $limit): Builder
    {
        return Form::query()->where('status', FormStatus::Draft->value)->orderByDesc('updated_at')->limit($limit);
    }

    /** @return Builder<FormVersion> versions published in the range, newest first */
    public function recentlyPublished(DateRange $range, int $limit): Builder
    {
        return FormVersion::query()
            ->where('published_at', '>=', $range->startUtc())
            ->where('published_at', '<', $range->endUtc())
            ->orderByDesc('published_at')
            ->limit($limit);
    }
}
