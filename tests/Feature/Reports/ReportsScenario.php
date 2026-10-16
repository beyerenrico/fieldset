<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Projection\SubmissionValueProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;

/**
 * A form with three published versions (BUILD_PROMPT §8.4 AC):
 * - v1: customer "Customer", amount (money), notes, kind (select)
 * - v2: customer renamed "Client", visit_date and items (repeater: qty, price) added
 * - v3: customer "Client name", done (checkbox) added, notes removed
 */
final class ReportsScenario
{
    public Organization $org;

    public User $owner;

    public User $worker;

    public Form $form;

    /** @var array<int, FormVersion> */
    public array $versions = [];

    private int $sequence = 0;

    public static function make(): self
    {
        $s = new self;
        $s->org = makeOrganization();
        $s->owner = ownerOf($s->org);
        $s->worker = User::factory()->memberOf($s->org, SystemRole::FieldWorker)->create(['name' => 'Walter Worker']);

        app(TenantContext::class)->run($s->org, function () use ($s): void {
            $s->form = Form::factory()->create(['key' => 'service', 'name' => 'Service report', 'created_by' => $s->owner->id]);
            foreach ([1 => self::v1(), 2 => self::v2(), 3 => self::v3()] as $number => $elements) {
                $s->versions[$number] = FormVersion::factory()->create([
                    'form_id' => $s->form->id, 'version' => $number, 'definition' => self::definition($elements),
                ]);
            }
            $s->form->forceFill(['current_version_id' => $s->versions[3]->id, 'status' => 'active'])->save();
        });

        return $s;
    }

    /** @param  list<array<string, mixed>>  $elements */
    public static function definition(array $elements): stdClass
    {
        $definition = json_decode((string) json_encode([
            'specVersion' => '1.0.0', 'key' => 'service', 'title' => 'Service report', 'locale' => 'de-DE',
            'pages' => [['key' => 'main', 'title' => 'Main', 'elements' => $elements]],
        ]));

        return $definition instanceof stdClass ? $definition : new stdClass;
    }

    /**
     * Creates a submission on the version and projects it (SubmissionValueProjector).
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(User $creator, array $data, int $version = 3, string $status = 'submitted', string $submittedAt = '2026-10-10 10:00'): Submission
    {
        return app(TenantContext::class)->run($this->org, function () use ($creator, $data, $version, $status, $submittedAt): Submission {
            $submission = Submission::factory()->create([
                'form_id' => $this->form->id, 'form_version_id' => $this->versions[$version]->id,
                'created_by' => $creator->id, 'updated_by' => $creator->id, 'data' => json_decode((string) json_encode((object) $data)),
            ]);
            $submission->forceFill([
                'status' => $status, 'number' => sprintf('SR-2026-%05d', ++$this->sequence),
                'submitted_at' => Carbon::parse($submittedAt, 'UTC'), 'submitted_by' => $creator->id,
            ])->save();
            app(SubmissionValueProjector::class)->project($submission->refresh());

            return $submission;
        });
    }

    /** Own fake disk: Storage::fake('local') is wiped by concurrent test runs (LEARNINGS #23). */
    public static function fakeExportDisk(): string
    {
        config(['reports.exports.disk' => 'reports-exports']);
        Storage::fake('reports-exports');

        return 'reports-exports';
    }

    /** A group sharing submissions (§8.6 "group") with the users as members. */
    public function group(string $name, User ...$users): Group
    {
        return app(TenantContext::class)->run($this->org, function () use ($name, $users): Group {
            $group = Group::query()->create(['name' => $name, 'share_submissions' => true]);
            foreach ($users as $user) {
                DB::table('group_user')->insert([
                    'organization_id' => $this->org->id, 'group_id' => $group->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $group;
        });
    }

    /** @return list<array<string, mixed>> */
    private static function v1(): array
    {
        return [
            ['type' => 'text', 'key' => 'customer', 'label' => 'Customer'],
            ['type' => 'money', 'key' => 'amount', 'label' => 'Amount', 'currency' => 'EUR'],
            ['type' => 'textarea', 'key' => 'notes', 'label' => 'Notes'],
            ['type' => 'select', 'key' => 'kind', 'label' => 'Kind', 'options' => [
                ['value' => 'repair', 'label' => 'Repair'], ['value' => 'maintenance', 'label' => 'Maintenance'],
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function v2(): array
    {
        $v1 = self::v1();
        $v1[0]['label'] = 'Client';

        return [...$v1,
            ['type' => 'date', 'key' => 'visit_date', 'label' => 'Visit date'],
            ['type' => 'section', 'key' => 'work', 'label' => 'Work', 'elements' => [
                ['type' => 'repeater', 'key' => 'items', 'label' => 'Items', 'elements' => [
                    ['type' => 'text', 'key' => 'description', 'label' => 'Description'],
                    ['type' => 'number', 'key' => 'qty', 'label' => 'Qty'],
                    ['type' => 'money', 'key' => 'price', 'label' => 'Price', 'currency' => 'EUR'],
                ]],
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function v3(): array
    {
        $elements = array_values(array_filter(self::v2(), fn (array $e): bool => $e['key'] !== 'notes'));
        $elements[0]['label'] = 'Client name';
        $elements[] = ['type' => 'checkbox', 'key' => 'done', 'label' => 'Done'];

        return $elements;
    }
}
