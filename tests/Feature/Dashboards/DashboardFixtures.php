<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Tests\Feature\Admin\Submissions\InboxScenario;

/** Builders for dashboard data in a given organization (each runs inside that tenant). */
final class DashboardFixtures
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function in(Organization $org, Closure $callback): mixed
    {
        return app(TenantContext::class)->run($org, $callback);
    }

    public static function form(Organization $org, User $creator, string $template = 'service_report'): Form
    {
        return self::in($org, fn (): Form => InboxScenario::publishedForm($creator, $template));
    }

    public static function submission(Form $form, User $creator, string $status = 'submitted', ?CarbonInterface $submittedAt = null, ?string $number = null): Submission
    {
        $org = Organization::query()->findOrFail($form->organization_id);
        $at = $status === 'draft' ? null : ($submittedAt ?? now())->copy()->utc()->format('Y-m-d H:i:s');

        return self::in($org, fn (): Submission => InboxScenario::submission($form, $creator, $status, $number, $at));
    }

    /** @param  array<string, mixed>  $attributes  forced attributes (status, due_at, assignee_*, completed_by, …) */
    public static function task(Submission $submission, array $attributes = []): Task
    {
        $org = Organization::query()->findOrFail($submission->organization_id);

        return self::in($org, function () use ($submission, $attributes): Task {
            $task = Task::factory()->create(['submission_uuid' => $submission->uuid]);
            $task->forceFill($attributes)->save();

            return $task->refresh();
        });
    }

    public static function failedRun(Organization $org, CarbonInterface $finishedAt, string $error = 'Webhook answered 500'): WorkflowRun
    {
        return self::in($org, function () use ($finishedAt, $error): WorkflowRun {
            $run = WorkflowRun::factory()->create(['workflow_id' => Workflow::factory()->create(['name' => 'Notify ERP'])->id]);
            $run->forceFill(['status' => 'failed', 'error' => $error, 'finished_at' => $finishedAt])->save();

            return $run;
        });
    }

    /** A sent mail about the submission; `$documents = false` makes it a notification mail. */
    public static function sentMail(Submission $submission, CarbonInterface $at, bool $documents = true, string $status = 'sent'): MailMessage
    {
        $org = Organization::query()->findOrFail($submission->organization_id);

        return self::in($org, function () use ($submission, $at, $documents, $status): MailMessage {
            $message = MailMessage::factory()->create(['submission_uuid' => $submission->uuid]);
            $message->forceFill([
                'status' => $status, 'mailable_class' => $documents ? BrandedMail::class : NotificationMail::class,
                'created_at' => $at, 'sent_at' => $status === 'sent' ? $at : null,
            ])->save();

            return $message;
        });
    }

    /** Ages a submission's updated_at (stale drafts). */
    public static function touchedAt(Submission $submission, CarbonInterface $at): void
    {
        Submission::query()->withoutTenant()->whereKey($submission->uuid)->toBase()->update(['updated_at' => $at]);
    }

    /** Calls a protected method of a Livewire component (widget data methods are not public on purpose). */
    public static function call(Testable $component, string $method): mixed
    {
        return (fn (): mixed => $this->{$method}())->call($component->instance());
    }

    public static function day(string $date): Carbon
    {
        return Carbon::parse($date.' 12:00', 'Europe/Berlin');
    }
}
