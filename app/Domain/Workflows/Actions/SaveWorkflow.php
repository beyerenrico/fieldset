<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Workflows\Enums\StepType;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Triggers\ConditionEvaluator;
use Cron\CronExpression;
use InvalidArgumentException;

/**
 * Creates or updates a workflow (§8.3): normalizes the trigger configuration to what the trigger uses
 * (`to_status`, `cron`, `form_ids`), rejects unusable JsonLogic conditions, unknown step types and
 * definitions that break WorkflowDefinitionRules (limits, set_status targets, recipients, one review per
 * submit), and keeps steps in the Builder shape `[{type, data}]`. Create/update are audited by the model (§8.11).
 */
final readonly class SaveWorkflow
{
    public function __construct(private ConditionEvaluator $conditions) {}

    /**
     * @param  array{name: string, description?: string|null, active?: bool, trigger_type: string, trigger_config?: array<string, mixed>, conditions?: mixed, steps?: array<mixed>}  $data
     */
    public function handle(?Workflow $workflow, array $data, User $actor): Workflow
    {
        $type = TriggerType::tryFrom($data['trigger_type']) ?? throw new InvalidArgumentException('Unknown trigger.');
        $raw = $data['conditions'] ?? null;
        $conditions = ConditionEvaluator::isEmpty($raw) ? null : $raw;
        $problem = $this->conditions->problem($conditions);
        if ($problem !== null) {
            throw new InvalidArgumentException(__('workflows.validation.logic', ['error' => $problem]));
        }
        $steps = self::steps($data['steps'] ?? []);
        if ($steps === []) {
            throw new InvalidArgumentException(__('workflows.validation.steps_required'));
        }
        WorkflowDefinitionRules::check($steps);
        $config = self::triggerConfig($type, $data['trigger_config'] ?? []);
        $active = (bool) ($data['active'] ?? true);
        WorkflowDefinitionRules::assertNoReviewConflict($workflow, $type, $config, $steps, $active);

        $attributes = [
            'name' => trim($data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'active' => $active,
            'trigger_type' => $type,
            'trigger_config' => $config,
            'conditions' => $conditions,
            'steps' => $steps,
            'updated_by' => $actor->id,
        ];
        if (! $workflow instanceof Workflow) {
            return Workflow::query()->create([...$attributes, 'created_by' => $actor->id]);
        }
        $workflow->fill($attributes)->save();

        return $workflow;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function triggerConfig(TriggerType $type, array $config): array
    {
        $normalized = [];
        if ($type === TriggerType::SubmissionStatusChanged && is_string($config['to_status'] ?? null) && SubmissionStatus::tryFrom($config['to_status']) !== null) {
            $normalized['to_status'] = $config['to_status'];
        }
        if ($type === TriggerType::Schedule) {
            $cron = trim((string) ($config['cron'] ?? ''));
            if (! CronExpression::isValidExpression($cron)) {
                throw new InvalidArgumentException(__('workflows.validation.cron'));
            }
            $normalized['cron'] = $cron;
        }
        $formIds = is_array($config['form_ids'] ?? null) ? array_values(array_unique(array_map(intval(...), array_filter($config['form_ids'], is_numeric(...))))) : [];
        if ($type->filtersForms() && $formIds !== []) {
            $normalized['form_ids'] = $formIds;
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $steps
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function steps(array $steps): array
    {
        $normalized = [];
        foreach ($steps as $step) {
            $type = is_array($step) ? StepType::tryFrom((string) ($step['type'] ?? '')) : null;
            if (! $type instanceof StepType) {
                throw new InvalidArgumentException(__('workflows.errors.unknown_step'));
            }
            $data = $step['data'] ?? [];
            $normalized[] = ['type' => $type->value, 'data' => is_array($data) ? $data : []];
        }

        return $normalized;
    }
}
