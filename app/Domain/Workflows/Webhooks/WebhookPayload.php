<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Webhooks;

use App\Domain\Forms\Models\Form;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Engine\StepContext;
use Fieldset\Json\Json;

/**
 * Webhook body (§10): the event name, ids, a submission summary and the submitted data snapshot (decimal
 * strings stay strings, `{}` stays an object). Runs without a submission carry the form instead.
 */
final class WebhookPayload
{
    public static function deliveryId(StepContext $context): string
    {
        return sprintf('run-%d-step-%d', $context->run->id, $context->step->index);
    }

    public static function encode(StepContext $context): string
    {
        $submission = $context->submission;
        $formId = $submission instanceof Submission ? $submission->form_id : ($context->run->context['trigger']['form_id'] ?? null);
        $form = is_numeric($formId) ? Form::query()->find((int) $formId) : null;

        return Json::encode([
            'event' => $context->run->trigger,
            'delivery_id' => self::deliveryId($context),
            'occurred_at' => now()->toIso8601String(),
            'organization' => ['id' => $context->organization->id, 'name' => $context->organization->name],
            'workflow' => ['id' => $context->run->workflow_id, 'name' => $context->run->workflowName(), 'run_id' => $context->run->id],
            'form' => $form instanceof Form ? ['id' => $form->id, 'key' => $form->key, 'name' => $form->name] : null,
            'submission' => $submission instanceof Submission ? self::summary($submission) : null,
            'data' => $submission?->data,
        ]);
    }

    /** @return array<string, mixed> */
    private static function summary(Submission $submission): array
    {
        $submission->loadMissing('formVersion');

        return [
            'uuid' => $submission->uuid,
            'number' => $submission->number,
            'status' => $submission->status->value,
            'form_id' => $submission->form_id,
            'version' => $submission->formVersion->version,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'submitted_by' => $submission->submitted_by,
        ];
    }
}
