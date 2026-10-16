<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Actions;

use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Workflows\Enums\StepType;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use InvalidArgumentException;

/**
 * Rules a workflow definition must meet before it is saved (ADR 0032, M4 review): limits on steps,
 * recipient lists and size (`workflows.limits`), `set_status` only to `completed`/`void` (a workflow never
 * moves a submission into or out of a review), `notify` with channels and recipients, `send_email` with a
 * recipient source, and at most one active workflow that requests a review when the same forms are submitted.
 */
final class WorkflowDefinitionRules
{
    /** Targets of `set_status`: review states are only changed by request_review and the review decision. */
    public const array SET_STATUS_TARGETS = [SubmissionStatus::Completed, SubmissionStatus::Void];

    /** Recipient lists of the steps (capped at `workflows.limits.recipients` entries each). */
    private const array RECIPIENT_LISTS = ['to_addresses', 'to_group_ids', 'user_ids', 'group_ids', 'roles'];

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $steps
     *
     * @throws InvalidArgumentException
     */
    public static function check(array $steps): void
    {
        $maxSteps = max(1, (int) config('workflows.limits.steps', 25));
        if (count($steps) > $maxSteps) {
            throw new InvalidArgumentException(__('workflows.validation.too_many_steps', ['max' => $maxSteps]));
        }
        $maxKb = max(1, (int) config('workflows.limits.definition_kb', 64));
        if (strlen((string) json_encode($steps)) > $maxKb * 1024) {
            throw new InvalidArgumentException(__('workflows.validation.too_large', ['max' => $maxKb]));
        }
        foreach ($steps as $step) {
            self::step(StepType::from($step['type']), $step['data']);
        }
    }

    /**
     * Refuses a second active workflow that requests a review on submit for overlapping forms: a submission
     * can be in one review only, the second run would fail (ADR 0032).
     *
     * @param  array<string, mixed>  $config  normalized trigger configuration
     * @param  list<array{type: string, data: array<string, mixed>}>  $steps
     */
    public static function assertNoReviewConflict(?Workflow $workflow, TriggerType $type, array $config, array $steps, bool $active): void
    {
        if (! $active || ! self::reviewsOnSubmit($type, $config) || ! self::hasReview($steps)) {
            return;
        }
        $formIds = is_array($config['form_ids'] ?? null) ? array_values(array_map(intval(...), $config['form_ids'])) : [];
        $conflict = Workflow::query()->where('active', true)->whereKeyNot($workflow->id ?? 0)
            ->whereIn('trigger_type', [TriggerType::SubmissionSubmitted->value, TriggerType::SubmissionStatusChanged->value])
            ->orderBy('id')->get()
            ->first(fn (Workflow $other): bool => self::reviewsOnSubmit($other->trigger_type, $other->trigger_config)
                && self::hasReview($other->steps) && self::overlap($formIds, $other->formIds()));
        if ($conflict instanceof Workflow) {
            throw new InvalidArgumentException(__('workflows.validation.review_conflict', ['name' => $conflict->name]));
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function step(StepType $type, array $data): void
    {
        foreach (self::RECIPIENT_LISTS as $list) {
            self::cap(self::count($data, $list), (int) config('workflows.limits.recipients', 50), 'too_many_recipients');
        }
        self::cap(self::count($data, 'to_fields'), (int) config('workflows.limits.fields', 10), 'too_many_fields');

        match ($type) {
            StepType::SetStatus => self::setStatus($data),
            StepType::Notify => self::notify($data),
            StepType::SendEmail => self::sendEmail($data),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $data */
    private static function setStatus(array $data): void
    {
        if (! in_array(SubmissionStatus::tryFrom((string) ($data['status'] ?? '')), self::SET_STATUS_TARGETS, true)) {
            throw new InvalidArgumentException(__('workflows.validation.status_target'));
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function notify(array $data): void
    {
        if (self::count($data, 'channels') === 0) {
            throw new InvalidArgumentException(__('workflows.validation.channels_required'));
        }
        if (self::count($data, 'user_ids') + self::count($data, 'group_ids') + self::count($data, 'roles') === 0) {
            throw new InvalidArgumentException(__('workflows.validation.notify_recipients_required'));
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function sendEmail(array $data): void
    {
        $sources = self::count($data, 'to_addresses') + self::count($data, 'to_fields') + self::count($data, 'to_group_ids')
            + (filter_var($data['to_submitter'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0);
        if ($sources === 0) {
            throw new InvalidArgumentException(__('workflows.validation.email_recipients_required'));
        }
    }

    private static function cap(int $count, int $max, string $key): void
    {
        if ($count > max(1, $max)) {
            throw new InvalidArgumentException(__('workflows.validation.'.$key, ['max' => max(1, $max)]));
        }
    }

    /** @param  array<string, mixed>  $data  number of non-empty entries of a list setting */
    private static function count(array $data, string $key): int
    {
        $values = $data[$key] ?? [];

        return is_array($values) ? count(array_filter($values, static fn (mixed $v): bool => is_scalar($v) && trim((string) $v) !== '')) : 0;
    }

    /** @param  array<string, mixed>  $config */
    private static function reviewsOnSubmit(TriggerType $type, array $config): bool
    {
        return $type === TriggerType::SubmissionSubmitted
            || ($type === TriggerType::SubmissionStatusChanged && in_array($config['to_status'] ?? null, [null, '', SubmissionStatus::Submitted->value], true));
    }

    /** @param  array<mixed>  $steps */
    private static function hasReview(array $steps): bool
    {
        return array_any($steps, fn ($step): bool => is_array($step) && ($step['type'] ?? null) === StepType::RequestReview->value);
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function overlap(array $a, array $b): bool
    {
        return $a === [] || $b === [] || array_intersect($a, $b) !== [];
    }
}
