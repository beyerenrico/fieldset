<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\Notifier;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Collection;
use Tests\Feature\Documents\DocumentFixtures;
use Tests\Support\FieldAppScenario;

/** Shared fixtures for the workflow tests: scenario, reviewers, workflows, submissions, notifier fake. */
final class WorkflowFixtures
{
    public static function notifier(): FakeNotifier
    {
        $fake = new FakeNotifier;
        app()->instance(Notifier::class, $fake);

        return $fake;
    }

    /** A published service report (FieldAppScenario) with documents on the fake disk, rendering off. */
    public static function scenario(): FieldAppScenario
    {
        DocumentFixtures::fakeDisk();
        config(['documents.render_on_submit' => false, 'workflows.complete_without_review' => true]);

        return FieldAppScenario::make();
    }

    /** A group with the given members (roles per member). */
    public static function group(Organization $org, string $name, User ...$members): Group
    {
        return app(TenantContext::class)->run($org, function () use ($org, $name, $members): Group {
            $group = Group::factory()->create(['name' => $name]);
            foreach ($members as $member) {
                app(SyncMemberGroups::class)->handle($org, $member, [$group->id]);
            }

            return $group;
        });
    }

    public static function reviewer(Organization $org): User
    {
        return memberOf($org, SystemRole::Reviewer);
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $steps
     * @param  array<string, mixed>  $config
     */
    public static function workflow(Organization $org, array $steps, TriggerType $trigger = TriggerType::SubmissionSubmitted, array $config = [], mixed $conditions = null): Workflow
    {
        return app(TenantContext::class)->run($org, fn (): Workflow => Workflow::factory()->trigger($trigger, $config)->withSteps($steps)->create([
            'conditions' => $conditions,
        ]));
    }

    /** @param  array<string, mixed>  $data */
    public static function step(string $type, array $data = []): array
    {
        return ['type' => $type, 'data' => $data];
    }

    public static function reviewStep(Group $group, ?int $slaHours = null): array
    {
        return self::step('request_review', ['assignee_type' => 'group', 'assignee_group_id' => $group->id, 'sla_hours' => $slaHours]);
    }

    /** A submitted service report by the scenario's worker (real SubmitSubmission, events after commit). */
    public static function submitted(FieldAppScenario $s, ?User $author = null): Submission
    {
        $submission = DocumentFixtures::submit($s, DocumentFixtures::draft($s, 2, $author), $author);

        return self::fresh($s, $submission);
    }

    public static function fresh(FieldAppScenario $s, Submission $submission): Submission
    {
        return app(TenantContext::class)->run($s->org, fn (): Submission => Submission::query()->findOrFail($submission->uuid));
    }

    /** @return Collection<int, WorkflowRun> */
    public static function runs(Organization $org): Collection
    {
        return app(TenantContext::class)->run($org, fn () => WorkflowRun::query()->with('stepRuns')->orderBy('id')->get());
    }

    public static function task(Organization $org, Submission $submission): Task
    {
        return app(TenantContext::class)->run($org, fn () => Task::query()->where('submission_uuid', $submission->uuid)->orderByDesc('id')->firstOrFail());
    }
}
