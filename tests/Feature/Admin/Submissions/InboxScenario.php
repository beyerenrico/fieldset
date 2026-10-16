<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Submissions;

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One organization with a form created by the owner (service report) and one created by a designer
 * (delivery note), and four submissions by two field workers:
 * - workerReport: worker, owner's form, submitted 2026-10-01, SR-2026-00001
 * - peerReport: peer, owner's form, in review, submitted 2026-10-03, SR-2026-00002
 * - workerDelivery: worker, designer's form, completed 2026-10-05, LS-2026-00001
 * - workerDraft: worker, owner's form, draft
 */
final class InboxScenario
{
    public Organization $org;

    public User $owner;

    public User $designer;

    public User $worker;

    public User $peer;

    public Form $ownerForm;

    public Form $designerForm;

    public Submission $workerReport;

    public Submission $peerReport;

    public Submission $workerDelivery;

    public Submission $workerDraft;

    public static function make(): self
    {
        $s = new self;
        $s->org = makeOrganization();
        $s->owner = ownerOf($s->org);
        $s->designer = memberOf($s->org, SystemRole::Designer);
        $s->worker = User::factory()->memberOf($s->org, SystemRole::FieldWorker)->create(['name' => 'Walter Worker']);
        $s->peer = User::factory()->memberOf($s->org, SystemRole::FieldWorker)->create(['name' => 'Petra Peer']);

        app(TenantContext::class)->run($s->org, function () use ($s): void {
            $s->ownerForm = self::publishedForm($s->owner, 'service_report');
            $s->designerForm = self::publishedForm($s->designer, 'delivery_note');
            $s->workerReport = self::submission($s->ownerForm, $s->worker, 'submitted', 'SR-2026-00001', '2026-10-01 10:00');
            $s->peerReport = self::submission($s->ownerForm, $s->peer, 'in_review', 'SR-2026-00002', '2026-10-03 10:00');
            $s->workerDelivery = self::submission($s->designerForm, $s->worker, 'completed', 'LS-2026-00001', '2026-10-05 10:00');
            $s->workerDraft = self::submission($s->ownerForm, $s->worker, 'draft', null, null);
        });

        return $s;
    }

    public static function publishedForm(User $creator, string $template): Form
    {
        $form = app(CreateFormFromTemplate::class)->handle($creator, $template);
        app(PublishForm::class)->handle($form, 'v1', $creator);

        return $form->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public static function submission(Form $form, User $creator, string $status, ?string $number, ?string $submittedAt, array $data = []): Submission
    {
        $submission = Submission::factory()->forForm($form)->create([
            'created_by' => $creator->id, 'updated_by' => $creator->id, 'data' => json_decode((string) json_encode((object) $data)),
        ]);
        $submission->forceFill([
            'status' => $status, 'number' => $number,
            'submitted_at' => $submittedAt !== null ? Carbon::parse($submittedAt, 'UTC') : null,
            'submitted_by' => $submittedAt !== null ? $creator->id : null,
        ])->save();

        return $submission;
    }

    /** Puts the users into one group that shares submissions (§8.6 "group"). */
    public function shareGroup(User ...$users): Group
    {
        return app(TenantContext::class)->run($this->org, function () use ($users): Group {
            $group = Group::query()->create(['name' => 'Crew '.count($users), 'share_submissions' => true]);
            foreach ($users as $user) {
                DB::table('group_user')->insert([
                    'organization_id' => $this->org->id, 'group_id' => $group->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $group;
        });
    }

    /** @return list<Submission> */
    public function nonDrafts(): array
    {
        return [$this->workerReport, $this->peerReport, $this->workerDelivery];
    }
}
