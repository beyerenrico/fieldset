<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Triggers;

use App\Domain\Forms\Events\FormPublished;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * A triggering event with its idempotency id (ADR 0032): status changes use the submission_events row id
 * (a resubmit is a new transition, so a new id), form.published the version id, schedules the minute,
 * manual runs a fresh id per click.
 */
final readonly class TriggerEvent
{
    public function __construct(
        public TriggerType $kind,
        public string $eventId,
        public ?string $submissionUuid = null,
        public ?int $formId = null,
        public ?SubmissionStatus $fromStatus = null,
        public ?SubmissionStatus $toStatus = null,
        public ?int $workflowId = null,
        public ?int $actorId = null,
        public ?int $formVersionId = null,
        public ?int $submissionEventId = null,
    ) {}

    public static function statusChanged(SubmissionStatusChanged $event): self
    {
        return new self(
            TriggerType::SubmissionStatusChanged, 'submission_event:'.$event->submissionEventId, $event->submissionUuid,
            fromStatus: SubmissionStatus::tryFrom($event->fromStatus), toStatus: SubmissionStatus::tryFrom($event->toStatus), actorId: $event->actorId,
            submissionEventId: $event->submissionEventId,
        );
    }

    public static function formPublished(FormPublished $event): self
    {
        return new self(TriggerType::FormPublished, 'form_version:'.$event->formVersionId, formId: $event->formId, actorId: $event->actorId, formVersionId: $event->formVersionId);
    }

    public static function schedule(Workflow $workflow, CarbonInterface $minute): self
    {
        return new self(TriggerType::Schedule, 'schedule:'.$minute->copy()->utc()->format('Y-m-d\TH:i'), workflowId: $workflow->id);
    }

    public static function manual(Workflow $workflow, string $submissionUuid, int $actorId, ?string $eventId = null): self
    {
        return new self(TriggerType::Manual, $eventId ?? 'manual:'.Str::uuid7(), $submissionUuid, workflowId: $workflow->id, actorId: $actorId);
    }

    public function withForm(?int $formId): self
    {
        return new self(
            $this->kind, $this->eventId, $this->submissionUuid, $formId, $this->fromStatus, $this->toStatus, $this->workflowId,
            $this->actorId, $this->formVersionId, $this->submissionEventId,
        );
    }

    /**
     * Workflow trigger types this event may fire (SQL pre-filter).
     *
     * @return list<string>
     */
    public function candidateTypes(): array
    {
        if ($this->kind !== TriggerType::SubmissionStatusChanged) {
            return [$this->kind->value];
        }

        return array_values(array_map(
            static fn (TriggerType $t): string => $t->value,
            array_filter(TriggerType::cases(), fn (TriggerType $t): bool => $this->toStatus instanceof SubmissionStatus && $t->firesOnTransitionTo($this->toStatus)),
        ));
    }

    public function matches(Workflow $workflow): bool
    {
        $type = $workflow->trigger_type;
        if ($this->workflowId !== null && $workflow->id !== $this->workflowId) {
            return false;
        }
        if ($type->filtersForms() && $workflow->formIds() !== [] && ! in_array($this->formId, $workflow->formIds(), true)) {
            return false;
        }
        if ($this->kind !== TriggerType::SubmissionStatusChanged || ! $this->toStatus instanceof SubmissionStatus) {
            return $type === $this->kind;
        }
        if ($type === TriggerType::SubmissionStatusChanged) {
            $to = $workflow->configValue('to_status');

            return $to === null || $to === $this->toStatus->value;
        }

        return $type->firesOnTransitionTo($this->toStatus);
    }

    /**
     * The `trigger` part of the condition context and of the stored run context.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->kind->value,
            'event_id' => $this->eventId,
            'from_status' => $this->fromStatus?->value,
            'to_status' => $this->toStatus?->value,
            'actor_id' => $this->actorId,
            'form_id' => $this->formId,
            'form_version_id' => $this->formVersionId,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
