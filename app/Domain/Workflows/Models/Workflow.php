<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Models;

use App\Domain\Forms\Casts\JsonTree;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Workflows\Enums\StepType;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Policies\WorkflowPolicy;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A workflow (BUILD_PROMPT §8.3): trigger + optional JsonLogic conditions + ordered steps. Steps are
 * stored in the Filament Builder shape `[{type, data}]`; every run keeps its own snapshot.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 * @property TriggerType $trigger_type
 * @property array<string, mixed> $trigger_config
 * @property mixed $conditions JSON tree (JsonLogic) or null
 * @property list<array{type: string, data: array<string, mixed>}> $steps
 * @property bool $active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(WorkflowFactory::class)]
#[UsePolicy(WorkflowPolicy::class)]
#[Fillable(['name', 'description', 'trigger_type', 'trigger_config', 'conditions', 'steps', 'active', 'created_by', 'updated_by'])]
class Workflow extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = [
        'description' => null, 'trigger_config' => '{}', 'conditions' => null, 'steps' => '[]', 'active' => true,
        'created_by' => null, 'updated_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => TriggerType::class,
            'trigger_config' => 'array',
            'conditions' => JsonTree::class,
            'steps' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<int> */
    public function formIds(): array
    {
        $ids = $this->trigger_config['form_ids'] ?? [];

        return is_array($ids) ? array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))) : [];
    }

    public function configValue(string $key): ?string
    {
        $value = $this->trigger_config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The definition a run executes (stored on the run, §8.10).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'trigger_type' => $this->trigger_type->value,
            'trigger_config' => $this->trigger_config,
            'conditions' => $this->conditions,
            'steps' => array_values($this->steps),
        ];
    }

    /**
     * Whether a definition has a step that changes the submission status itself (ADR 0032).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function snapshotManagesStatus(array $snapshot): bool
    {
        foreach ((array) ($snapshot['steps'] ?? []) as $step) {
            $type = is_array($step) ? StepType::tryFrom((string) ($step['type'] ?? '')) : null;
            if ($type?->managesStatus() === true) {
                return true;
            }
        }

        return false;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'trigger_type', 'trigger_config', 'conditions', 'steps', 'active'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }
}
