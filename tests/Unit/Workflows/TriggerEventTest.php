<?php

declare(strict_types=1);

use App\Domain\Submissions\Events\SubmissionStatusChanged;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Triggers\ConditionEvaluator;
use App\Domain\Workflows\Triggers\TriggerEvent;
use FormSchema\Logic\JsonLogic;

/* Trigger matching and condition evaluation without the database. */

function wf(TriggerType $type, array $config = [], int $id = 1): Workflow
{
    $workflow = new Workflow;
    $workflow->forceFill(['id' => $id, 'trigger_type' => $type, 'trigger_config' => $config]);

    return $workflow;
}

function transition(string $from, string $to, int $eventId = 7): TriggerEvent
{
    return TriggerEvent::statusChanged(new SubmissionStatusChanged($eventId, 'uuid', 1, $from, $to, null))->withForm(3);
}

it('derives the submission triggers from one status change', function (): void {
    $submitted = transition('draft', 'submitted');
    $approved = transition('in_review', 'approved');
    $rejected = transition('in_review', 'changes_requested');

    expect($submitted->matches(wf(TriggerType::SubmissionSubmitted)))->toBeTrue()
        ->and($submitted->matches(wf(TriggerType::SubmissionApproved)))->toBeFalse()
        ->and($approved->matches(wf(TriggerType::SubmissionApproved)))->toBeTrue()
        ->and($rejected->matches(wf(TriggerType::SubmissionRejected)))->toBeTrue()
        ->and($rejected->matches(wf(TriggerType::SubmissionStatusChanged)))->toBeTrue()
        ->and($rejected->matches(wf(TriggerType::SubmissionStatusChanged, ['to_status' => 'changes_requested'])))->toBeTrue()
        ->and($rejected->matches(wf(TriggerType::SubmissionStatusChanged, ['to_status' => 'approved'])))->toBeFalse()
        ->and($submitted->matches(wf(TriggerType::Manual)))->toBeFalse()
        ->and($submitted->candidateTypes())->toEqualCanonicalizing(['submission.submitted', 'submission.status_changed'])
        ->and($submitted->eventId)->toBe('submission_event:7');
});

it('filters by form ids and by workflow for manual and scheduled runs', function (): void {
    expect(transition('draft', 'submitted')->matches(wf(TriggerType::SubmissionSubmitted, ['form_ids' => [3]])))->toBeTrue()
        ->and(transition('draft', 'submitted')->matches(wf(TriggerType::SubmissionSubmitted, ['form_ids' => ['4']])))->toBeFalse()
        ->and(TriggerEvent::manual(wf(TriggerType::Manual, [], 5), 'uuid', 1)->matches(wf(TriggerType::Manual, [], 6)))->toBeFalse()
        ->and(TriggerEvent::manual(wf(TriggerType::Manual, [], 5), 'uuid', 1)->matches(wf(TriggerType::Manual, [], 5)))->toBeTrue();
});

it('evaluates conditions with JS truthiness and reports unusable expressions', function (): void {
    $conditions = new ConditionEvaluator(new JsonLogic);
    $context = ['submission' => ['data' => (object) ['total' => '1200.50', 'zero' => '0']], 'trigger' => ['type' => 'manual']];

    expect($conditions->passes(null, $context))->toBeTrue()
        ->and($conditions->passes(new stdClass, $context))->toBeTrue()
        ->and($conditions->passes(json_decode('{">": [{"var": "submission.data.total"}, 1000]}'), $context))->toBeTrue()
        ->and($conditions->passes(json_decode('{"var": "submission.data.zero"}'), $context))->toBeTrue()   // "0" is truthy (JS)
        ->and($conditions->passes(json_decode('{"var": "submission.data.missing"}'), $context))->toBeFalse()
        ->and($conditions->passes(json_decode('{"nope": [1]}'), $context))->toBeFalse()
        ->and($conditions->problem(json_decode('{"nope": [1]}')))->not->toBeNull()
        ->and($conditions->problem(json_decode('{"==": [1, 1]}')))->toBeNull();
});
