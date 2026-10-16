<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Models;

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\TaskOutcome;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Policies\TaskPolicy;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A human step (§6.4): a review task created by a `request_review` step, assigned to a user or a
 * group, due after the SLA. Completed once (approve or reject with comment).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $type
 * @property string $submission_uuid
 * @property int|null $workflow_run_id
 * @property int|null $workflow_step_run_id
 * @property int|null $assignee_user_id
 * @property int|null $assignee_group_id
 * @property Carbon|null $due_at
 * @property TaskStatus $status
 * @property TaskOutcome|null $outcome
 * @property string|null $comment
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $reminded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Submission $submission
 * @property-read User|null $assigneeUser
 * @property-read Group|null $assigneeGroup
 * @property-read User|null $completer
 * @property-read WorkflowRun|null $run
 */
#[UseFactory(TaskFactory::class)]
#[UsePolicy(TaskPolicy::class)]
#[Fillable(['type', 'submission_uuid', 'workflow_run_id', 'workflow_step_run_id', 'assignee_user_id', 'assignee_group_id', 'due_at'])]
class Task extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    public const string TYPE_REVIEW = 'review';

    protected $attributes = [
        'type' => self::TYPE_REVIEW, 'workflow_run_id' => null, 'workflow_step_run_id' => null, 'assignee_user_id' => null,
        'assignee_group_id' => null, 'due_at' => null, 'status' => 'open', 'outcome' => null, 'comment' => null,
        'completed_by' => null, 'completed_at' => null, 'reminded_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'outcome' => TaskOutcome::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === TaskStatus::Open;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at instanceof Carbon && $this->due_at->isPast();
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_uuid', 'uuid');
    }

    /** @return BelongsTo<User, $this> */
    public function assigneeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** @return BelongsTo<Group, $this> */
    public function assigneeGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'assignee_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
