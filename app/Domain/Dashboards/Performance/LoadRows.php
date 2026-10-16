<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use App\Domain\Mail\Mailables\BrandedMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Row builders of the load generator. Submissions are spread uniformly over the last 365 days with a
 * status mix close to a busy organization: 5 % drafts, 10 % submitted, 10 % in review, 2 % changes
 * requested, 3 % approved, 68 % completed, 2 % void.
 */
final class LoadRows
{
    /** @var array<string, int> status => cumulative weight (of 100) */
    private const array STATUS_WEIGHTS = [
        'draft' => 5, 'submitted' => 15, 'in_review' => 25, 'changes_requested' => 27,
        'approved' => 30, 'completed' => 98, 'void' => 100,
    ];

    private ?string $payload = null;

    /** @return array<string, mixed> */
    public function submission(LoadContext $context, int $sequence): array
    {
        $status = $this->status();
        $user = $context->member();
        $submitted = $status === 'draft' ? null : $context->now->subMinutes(mt_rand(0, 365 * 24 * 60));
        $created = $submitted?->subMinutes(mt_rand(5, 240)) ?? $context->now->subMinutes(mt_rand(60, 90 * 24 * 60));
        $updated = match (true) {
            $submitted === null => $created->addMinutes(mt_rand(1, 120)),
            $status === 'submitted' => $submitted,
            default => $this->notAfter($submitted->addMinutes(mt_rand(10, 3 * 24 * 60)), $context->now),
        };

        return [
            'uuid' => (string) Str::uuid7($created), 'organization_id' => $context->organizationId, 'form_id' => $context->formId,
            'form_version_id' => $context->formVersionId, 'status' => $status, 'data' => '{}', 'revision' => 1,
            'number' => $submitted === null ? null : sprintf('LT-%s-%06d', $context->run, $sequence),
            'created_by' => $user, 'updated_by' => $user, 'submitted_by' => $submitted === null ? null : $user, 'submitted_at' => $submitted,
            'device' => json_encode(['generator' => LoadGenerator::MARKER, 'run' => $context->run]), 'geo' => null,
            'created_at' => $created, 'updated_at' => $updated,
        ];
    }

    /**
     * @param  array<string, mixed>  $submission
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function value(LoadContext $context, array $submission, array $row): array
    {
        return [
            'organization_id' => $context->organizationId, 'form_id' => $context->formId, 'form_version_id' => $context->formVersionId,
            'submission_uuid' => $submission['uuid'], 'status' => $submission['status'], 'submitted_at' => $submission['submitted_at'],
        ] + $row;
    }

    /**
     * Open review tasks for submissions in review; decided tasks for changes requested and 40 % of approved/completed ones.
     *
     * @param  list<array<string, mixed>>  $submissions
     * @return list<array<string, mixed>>
     */
    public function tasks(LoadContext $context, array $submissions): array
    {
        $tasks = [];
        foreach ($submissions as $s) {
            $decided = $s['status'] === 'changes_requested' || (in_array($s['status'], ['approved', 'completed'], true) && mt_rand(1, 10) <= 4);
            if ($s['status'] !== 'in_review' && ! $decided) {
                continue;
            }
            /** @var CarbonImmutable $at */
            $at = $s['submitted_at'];
            $completedAt = $decided ? $this->notAfter($at->addMinutes(mt_rand(30, 2 * 24 * 60)), $context->now) : null;
            $tasks[] = [
                'organization_id' => $context->organizationId, 'type' => 'review', 'submission_uuid' => $s['uuid'],
                'workflow_run_id' => null, 'workflow_step_run_id' => null,
                'assignee_user_id' => $context->groupId === null ? $context->member() : null, 'assignee_group_id' => $context->groupId,
                'due_at' => $at->addDay(), 'status' => $decided ? 'completed' : 'open',
                'outcome' => $decided ? ($s['status'] === 'changes_requested' ? 'rejected' : 'approved') : null, 'comment' => null,
                'completed_by' => $decided ? $context->member() : null, 'completed_at' => $completedAt, 'reminded_at' => null,
                'created_at' => $at->addMinute(), 'updated_at' => $completedAt ?? $at->addMinute(),
            ];
        }

        return $tasks;
    }

    /**
     * One rendition row (no file) per approved or completed submission, when the organization has a design.
     *
     * @param  list<array<string, mixed>>  $submissions
     * @return list<array<string, mixed>>
     */
    public function renditions(LoadContext $context, array $submissions): array
    {
        if ($context->designVersionId === null) {
            return [];
        }
        $rows = [];
        foreach ($submissions as $s) {
            if (! in_array($s['status'], ['approved', 'completed'], true)) {
                continue;
            }
            /** @var CarbonImmutable $at */
            $at = $s['submitted_at'];
            $uuid = (string) Str::uuid7($at);
            $rows[] = [
                'uuid' => $uuid, 'organization_id' => $context->organizationId, 'submission_uuid' => $s['uuid'],
                'form_version_id' => $context->formVersionId, 'design_version_id' => $context->designVersionId,
                'input_hash' => hash('sha256', $uuid), 'file_sha256' => str_repeat('0', 64), 'renderer_version' => 'load-test',
                'page_count' => 1, 'disk' => 'local', 'path' => 'load-test/'.$uuid.'.pdf', 'size' => 0, 'reason' => 'submitted',
                'created_by' => null, 'created_at' => $at->addMinutes(2),
            ];
        }

        return $rows;
    }

    /**
     * Workflow runs for 40 % of the non-drafts (3 % of them failed), when the organization has a workflow.
     *
     * @param  list<array<string, mixed>>  $submissions
     * @return list<array<string, mixed>>
     */
    public function runs(LoadContext $context, array $submissions): array
    {
        if ($context->workflowId === null) {
            return [];
        }
        $rows = [];
        foreach ($submissions as $s) {
            if ($s['status'] === 'draft' || mt_rand(1, 10) > 4) {
                continue;
            }
            /** @var CarbonImmutable $at */
            $at = $s['submitted_at'];
            $failed = mt_rand(1, 100) <= 3;
            $rows[] = [
                'organization_id' => $context->organizationId, 'workflow_id' => $context->workflowId, 'submission_uuid' => $s['uuid'],
                'trigger' => 'submission.submitted', 'event_id' => (string) Str::uuid7($at),
                'definition_snapshot' => '{"name":"Load test","trigger_type":"submission.submitted","trigger_config":{},"conditions":null,"steps":[]}',
                'context' => '{}', 'status' => $failed ? 'failed' : 'completed', 'current_step' => 0,
                'started_at' => $at, 'finished_at' => $at->addMinute(), 'error' => $failed ? 'Load test: webhook timed out' : null,
                'created_at' => $at, 'updated_at' => $at->addMinute(),
            ];
        }

        return $rows;
    }

    /**
     * Sent document mails (branded template) for 25 % of the completed submissions.
     *
     * @param  list<array<string, mixed>>  $submissions
     * @return list<array<string, mixed>>
     */
    public function mails(LoadContext $context, array $submissions): array
    {
        $rows = [];
        foreach ($submissions as $s) {
            if ($s['status'] !== 'completed' || mt_rand(1, 4) > 1) {
                continue;
            }
            /** @var CarbonImmutable $at */
            $at = $s['submitted_at']->addMinutes(3);
            $rows[] = [
                'organization_id' => $context->organizationId, 'recipient_email' => 'kunde@example.com', 'recipient_name' => null,
                'subject' => 'Servicebericht '.$s['number'], 'status' => 'sent', 'submission_uuid' => $s['uuid'],
                'mailable_class' => BrandedMail::class, 'message_id' => null, 'error' => null, 'attempts' => 1,
                'payload' => $this->payload ??= Crypt::encryptString(''), 'queued_at' => $at, 'sent_at' => $at, 'failed_at' => null,
                'workflow_step_run_id' => null, 'created_at' => $at, 'updated_at' => $at,
            ];
        }

        return $rows;
    }

    private function status(): string
    {
        $roll = mt_rand(1, 100);
        foreach (self::STATUS_WEIGHTS as $status => $upTo) {
            if ($roll <= $upTo) {
                return $status;
            }
        }

        return 'completed';
    }

    private function notAfter(CarbonImmutable $at, CarbonImmutable $limit): CarbonImmutable
    {
        return $at->greaterThan($limit) ? $limit : $at;
    }
}
