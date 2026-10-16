<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use App\Domain\Forms\Models\Form;
use App\Domain\Submissions\Models\Submission;
use stdClass;

/** Builds the JsonLogic context of workflow conditions (ADR 0032). */
final class RunContext
{
    /** @return array<string, mixed> */
    public static function build(TriggerEvent $event, ?Submission $submission, ?Form $form): array
    {
        return [
            'submission' => $submission instanceof Submission ? self::submission($submission, $form) : null,
            'form' => $form instanceof Form ? ['id' => $form->id, 'key' => $form->key, 'name' => $form->name, 'category' => $form->category] : null,
            'trigger' => $event->toArray(),
        ];
    }

    /**
     * A context with every key present, used to validate expressions in the editor.
     *
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        return [
            'submission' => ['uuid' => '', 'data' => new stdClass, 'status' => 'submitted', 'number' => '', 'form_key' => '', 'version' => 1,
                'submitted_at' => null, 'submitted_by' => null],
            'form' => ['id' => 0, 'key' => '', 'name' => '', 'category' => null],
            'trigger' => ['type' => 'manual', 'event_id' => ''],
        ];
    }

    /** @return array<string, mixed> */
    private static function submission(Submission $submission, ?Form $form): array
    {
        $submission->loadMissing('formVersion');

        return [
            'uuid' => $submission->uuid,
            'data' => $submission->data,
            'status' => $submission->status->value,
            'number' => $submission->number,
            'form_key' => $form?->key,
            'version' => $submission->formVersion->version,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'submitted_by' => $submission->submitted_by,
        ];
    }
}
