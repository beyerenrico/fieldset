<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Workflows\Enums\StepStatus;
use App\Domain\Workflows\Policies\WorkflowStepRunPolicy;
use Database\Factories\WorkflowStepRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a run: status, attempts, a short log (timeline in Filament) and the step's output
 * (created task, queued mails, rendition, webhook response).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $workflow_run_id
 * @property int $index
 * @property string $type
 * @property StepStatus $status
 * @property int $attempts
 * @property list<array{at: string, level: string, message: string}> $log
 * @property array<string, mixed> $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WorkflowRun $run
 */
#[UseFactory(WorkflowStepRunFactory::class)]
#[UsePolicy(WorkflowStepRunPolicy::class)]
#[Fillable(['workflow_run_id', 'index', 'type', 'status'])]
class WorkflowStepRun extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<WorkflowStepRunFactory> */
    use HasFactory;

    /** Older log entries are dropped beyond this many. */
    public const int LOG_LIMIT = 50;

    protected $attributes = [
        'status' => 'pending', 'attempts' => 0, 'log' => '[]', 'output' => '{}',
        'started_at' => null, 'finished_at' => null, 'error' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'attempts' => 'integer',
            'index' => 'integer',
            'log' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** Appends a log line (not saved). */
    public function appendLog(string $level, string $message): void
    {
        $log = [...$this->log, ['at' => now()->toIso8601String(), 'level' => $level, 'message' => mb_substr($message, 0, 1000)]];
        $this->log = array_slice($log, -self::LOG_LIMIT);
    }

    /** @param  array<string, mixed>  $values  merged into the output (not saved) */
    public function mergeOutput(array $values): void
    {
        $this->output = [...$this->output, ...$values];
    }

    /** Removes output keys (not saved). */
    public function forgetOutput(string ...$keys): void
    {
        $this->output = array_diff_key($this->output, array_flip($keys));
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
