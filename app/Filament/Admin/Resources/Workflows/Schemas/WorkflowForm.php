<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\Schemas;

use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Triggers\ConditionEvaluator;
use App\Filament\Admin\Resources\Workflows\Support\WorkflowOptions;
use Closure;
use Cron\CronExpression;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use FormSchema\Json\Json;

/**
 * Workflow editor (§8.3): trigger with its configuration, JsonLogic conditions as raw JSON (validated by
 * evaluating against a sample context) and the steps Builder. fill()/data() map between the model and
 * the form state.
 */
final class WorkflowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('workflows.fields.name'))->required()->maxLength(120),
                Toggle::make('active')->label(__('workflows.fields.active'))->default(true)->inline(false),
                Textarea::make('description')->label(__('workflows.fields.description'))->rows(2)->columnSpanFull(),
            ]),
            Section::make(__('workflows.fields.trigger_type'))->columns(2)->schema([
                Select::make('trigger_type')->label(__('workflows.fields.trigger_type'))->options(TriggerType::options())
                    ->required()->live()->default(TriggerType::SubmissionSubmitted->value),
                Select::make('to_status')->label(__('workflows.fields.to_status'))->placeholder(__('workflows.fields.any_status'))
                    ->options(fn (): array => WorkflowOptions::statuses([SubmissionStatus::Draft]))
                    ->visible(fn (Get $get): bool => $get('trigger_type') === TriggerType::SubmissionStatusChanged->value),
                TextInput::make('cron')->label(__('workflows.fields.cron'))->helperText(__('workflows.fields.cron_help'))
                    ->visible(fn (Get $get): bool => $get('trigger_type') === TriggerType::Schedule->value)
                    ->required(fn (Get $get): bool => $get('trigger_type') === TriggerType::Schedule->value)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! CronExpression::isValidExpression(trim((string) $value))) {
                            $fail(__('workflows.validation.cron'));
                        }
                    }),
                Select::make('form_ids')->label(__('workflows.fields.form_ids'))->helperText(__('workflows.fields.form_ids_help'))
                    ->multiple()->options(WorkflowOptions::forms(...))
                    ->visible(fn (Get $get): bool => TriggerType::tryFrom((string) $get('trigger_type'))?->filtersForms() ?? false),
            ]),
            Section::make(__('workflows.fields.conditions'))->collapsible()->schema([
                Textarea::make('conditions')->hiddenLabel()->rows(5)->extraInputAttributes(['class' => 'font-mono'])
                    ->helperText(__('workflows.fields.conditions_help'))->rule(fn (): Closure => self::conditionsRule()),
            ]),
            Section::make(__('workflows.fields.steps'))->schema([WorkflowStepBlocks::builder()->hiddenLabel()]),
        ]);
    }

    /** @return array<string, mixed> form state of a workflow */
    public static function fill(Workflow $workflow): array
    {
        return [
            'name' => $workflow->name, 'description' => $workflow->description, 'active' => $workflow->active,
            'trigger_type' => $workflow->trigger_type->value,
            'to_status' => $workflow->configValue('to_status'),
            'cron' => $workflow->configValue('cron'),
            'form_ids' => $workflow->formIds(),
            'conditions' => ConditionEvaluator::isEmpty($workflow->conditions) ? '' : json_encode($workflow->conditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'steps' => $workflow->steps,
        ];
    }

    /**
     * Form state → SaveWorkflow input.
     *
     * @param  array<string, mixed>  $state
     * @return array{name: string, description: string|null, active: bool, trigger_type: string, trigger_config: array<string, mixed>, conditions: mixed, steps: array<mixed>}
     */
    public static function data(array $state): array
    {
        $conditions = trim((string) ($state['conditions'] ?? ''));

        return [
            'name' => (string) ($state['name'] ?? ''),
            'description' => is_string($state['description'] ?? null) ? $state['description'] : null,
            'active' => (bool) ($state['active'] ?? true),
            'trigger_type' => (string) ($state['trigger_type'] ?? ''),
            'trigger_config' => ['to_status' => $state['to_status'] ?? null, 'cron' => $state['cron'] ?? null, 'form_ids' => $state['form_ids'] ?? []],
            'conditions' => $conditions === '' ? null : Json::decode($conditions),
            'steps' => array_values(is_array($state['steps'] ?? null) ? $state['steps'] : []),
        ];
    }

    private static function conditionsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $json = trim((string) $value);
            if ($json === '') {
                return;
            }
            if (! json_validate($json)) {
                $fail(__('workflows.validation.json'));

                return;
            }
            $problem = app(ConditionEvaluator::class)->problem(Json::decode($json));
            if ($problem !== null) {
                $fail(__('workflows.validation.logic', ['error' => $problem]));
            }
        };
    }
}
