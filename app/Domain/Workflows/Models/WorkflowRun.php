<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Policies\WorkflowRunPolicy;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of a workflow for one triggering event (§8.3): stores the definition snapshot it runs,
 * the trigger context and its progress. Unique per (workflow, submission, event id).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $workflow_id
 * @property string|null $submission_uuid
 * @property string $trigger
 * @property string $event_id
 * @property array<string, mixed> $definition_snapshot
 * @property array<string, mixed> $context
 * @property RunStatus $status
 * @property int $current_step
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Workflow $workflow
 * @property-read Submission|null $submission
 */
#[UseFactory(WorkflowRunFactory::class)]
#[UsePolicy(WorkflowRunPolicy::class)]
#[Fillable(['workflow_id', 'submission_uuid', 'trigger', 'event_id', 'definition_snapshot', 'context', 'status', 'current_step'])]
class WorkflowRun extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    protected $attributes = [
        'submission_uuid' => null, 'context' => '{}', 'status' => 'pending', 'current_step' => 0,
        'started_at' => null, 'finished_at' => null, 'error' => null,
    ];

    protected function casts(): array
    {
        return [
            'definition_snapshot' => 'array',
            'context' => 'array',
            'status' => RunStatus::class,
            'current_step' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    public function steps(): array
    {
        $steps = $this->definition_snapshot['steps'] ?? [];
        $list = [];
        foreach (is_array($steps) ? $steps : [] as $step) {
            if (is_array($step) && is_string($step['type'] ?? null)) {
                $data = $step['data'] ?? [];
                $list[] = ['type' => $step['type'], 'data' => is_array($data) ? $data : []];
            }
        }

        return $list;
    }

    /** @return array{type: string, data: array<string, mixed>}|null */
    public function step(int $index): ?array
    {
        return $this->steps()[$index] ?? null;
    }

    public function workflowName(): string
    {
        return (string) ($this->definition_snapshot['name'] ?? '');
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_uuid', 'uuid');
    }

    /** @return HasMany<WorkflowStepRun, $this> */
    public function stepRuns(): HasMany
    {
        return $this->hasMany(WorkflowStepRun::class)->orderBy('index');
    }
}
