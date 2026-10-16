<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Invitations\BulkInvitationPlanner;
use App\Domain\Identity\Invitations\CsvInvitationException;
use App\Domain\Identity\Invitations\CsvInvitationParser;
use App\Domain\Identity\Invitations\PlannedInvitation;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\MemberInvitation;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Members\Pages\ManageMembers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/* M3-T11: CSV bulk member invitations (BUILD_PROMPT §8.12). */

beforeEach(function (): void {
    Notification::fake();
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->crew = app(TenantContext::class)->run($this->org, fn () => Group::query()->create(['name' => 'Crew Nord']));
    $this->office = app(TenantContext::class)->run($this->org, fn () => Group::query()->create(['name' => 'Büro']));
});

function csvFile(string $contents, string $name = 'members.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

/** @return array<string, PlannedInvitation> rows by email */
function planCsv(User $actor, string $csv): array
{
    $report = app(BulkInvitationPlanner::class)->fromCsv($actor, test()->org, $csv);

    return collect($report->rows)->keyBy(fn (PlannedInvitation $r): string => $r->email !== '' ? $r->email : 'line '.$r->line)->all();
}

describe('parsing', function (): void {
    it('reads comma files with BOM, CRLF, quotes and multi-value cells', function (): void {
        $rows = (new CsvInvitationParser)->parse("\xEF\xBB\xBFemail,name,roles,groups\r\n"
            ."anna@example.com,\"Schmidt, Anna\",field_worker|reviewer,Crew Nord|Büro\r\n"
            ."\r\n"
            ."ben@example.com,Ben,viewer,\r\n");

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->line)->toBe(2)->and($rows[0]->name)->toBe('Schmidt, Anna')
            ->and($rows[0]->roles)->toBe(['field_worker', 'reviewer'])->and($rows[0]->groups)->toBe(['Crew Nord', 'Büro'])
            ->and($rows[1]->line)->toBe(4)->and($rows[1]->groups)->toBe([]);
    });

    it('reads semicolon files with German headers and comma-separated roles', function (): void {
        $rows = (new CsvInvitationParser)->parse("E-Mail;Name;Rollen;Gruppen\ncarl@example.com;Carl;Außendienst, Prüfer;Crew Nord\n");

        expect($rows[0]->email)->toBe('carl@example.com')
            ->and($rows[0]->roles)->toBe(['Außendienst', 'Prüfer'])
            ->and($rows[0]->groups)->toBe(['Crew Nord']);
    });

    it('reads Windows-1252 files from spreadsheet programs', function (): void {
        $rows = (new CsvInvitationParser)->parse(mb_convert_encoding("email;name;roles\ndora@example.com;Dora Müller;viewer\n", 'Windows-1252', 'UTF-8'));

        expect($rows[0]->name)->toBe('Dora Müller');
    });

    // Datasets resolve before the app boots: translate inside the test.
    it('rejects unusable files', function (string $csv, string $key, array $params): void {
        expect(fn () => (new CsvInvitationParser)->parse($csv))->toThrow(CsvInvitationException::class, __('identity.csv_import.errors.'.$key, $params));
    })->with([
        'no roles column' => ["email,name\na@example.com,A\n", 'missing_columns', ['columns' => 'roles']],
        'header only' => ["email,roles\n\n", 'empty', []],
        'empty file' => ['', 'empty', []],
        'too many rows' => ["email,roles\n".str_repeat("x@example.com,viewer\n", CsvInvitationParser::MAX_ROWS + 1), 'too_many_rows', ['max' => CsvInvitationParser::MAX_ROWS]],
        'too large' => [str_repeat('a', CsvInvitationParser::MAX_BYTES + 1), 'too_large', ['max' => 512]],
    ]);

    it('accepts exactly the row limit', function (): void {
        $csv = "email,roles\n";
        for ($i = 1; $i <= CsvInvitationParser::MAX_ROWS; $i++) {
            $csv .= "user{$i}@example.com,viewer\n";
        }

        expect((new CsvInvitationParser)->parse($csv))->toHaveCount(CsvInvitationParser::MAX_ROWS);
    });
});

describe('validation', function (): void {
    it('resolves roles by name or label and groups by name, case-insensitively', function (): void {
        $rows = planCsv($this->owner, "email,name,roles,groups\nAnna@Example.com,Anna,Außendienst|REVIEWER,crew nord|Büro\nben@example.com,,viewer,\n");

        expect($rows['anna@example.com']->isValid())->toBeTrue()
            ->and($rows['anna@example.com']->roles)->toBe(['field_worker', 'reviewer'])
            ->and($rows['anna@example.com']->groupIds)->toBe([$this->crew->id, $this->office->id])
            ->and($rows['ben@example.com']->name)->toBe('ben');
    });

    it('reports every problem per row', function (): void {
        $member = memberOf($this->org, SystemRole::Viewer);
        $invited = app(InviteMember::class)->handle($this->owner, $this->org, 'pending@example.com', 'Pending', ['viewer']);

        $rows = planCsv($this->owner, "email,name,roles,groups\n"
            ."not-an-email,X,viewer,\n"
            ."a@example.com,A,wizard,Nowhere\n"
            ."b@example.com,B,,\n"
            ."c@example.com,C,viewer,\n"
            ."C@example.com,C again,viewer,\n"
            ."{$member->email},M,viewer,\n"
            .'d@example.com,'.str_repeat('n', 121).",viewer,\n"
            ."pending@example.com,Pending,designer,\n");

        expect($rows['not-an-email']->errors)->toBe([__('identity.csv_import.errors.invalid_email')])
            ->and($rows['a@example.com']->errors)->toBe([
                __('identity.csv_import.errors.unknown_role', ['role' => 'wizard']),
                __('identity.csv_import.errors.unknown_group', ['group' => 'Nowhere']),
            ])
            ->and($rows['b@example.com']->errors)->toBe([__('identity.csv_import.errors.roles_required')])
            ->and($rows['c@example.com']->errors)->toBe([__('identity.csv_import.errors.duplicate', ['line' => 5])])
            ->and($rows[$member->email]->errors)->toBe([__('identity.csv_import.errors.already_member')])
            ->and($rows['d@example.com']->errors)->toBe([__('identity.csv_import.errors.name_too_long', ['max' => 120])])
            ->and($rows['pending@example.com']->isValid())->toBeTrue()
            ->and($rows['pending@example.com']->notices)->toBe([__('identity.csv_import.notices.already_invited')])
            ->and($invited->status)->toBe(MembershipStatus::Invited);
    });

    it('applies the role-escalation guard to the importing admin', function (): void {
        $admin = memberOf($this->org, SystemRole::Admin);

        $rows = planCsv($admin, "email,roles\nboss@example.com,owner\nok@example.com,admin|designer\n");

        expect($rows['boss@example.com']->errors)->toBe([__('identity.csv_import.errors.role_not_assignable', ['role' => 'owner'])])
            ->and($rows['ok@example.com']->isValid())->toBeTrue();
    });
});

describe('Members page', function (): void {
    it('sends queued invitations for the valid rows and audits each plus one summary', function (): void {
        actingInAdmin($this->owner, $this->org);

        Livewire::test(ManageMembers::class)
            ->callAction('importCsv', ['file' => csvFile("email;name;roles;groups\nanna@example.com;Anna;field_worker;Crew Nord\nbad;Bad;viewer;\nben@example.com;Ben;reviewer|viewer;\n")])
            ->assertHasNoActionErrors()
            ->assertNotified(__('identity.csv_import.sent', ['count' => 2]));

        $anna = User::query()->where('email', 'anna@example.com')->sole();
        $ben = User::query()->where('email', 'ben@example.com')->sole();
        Notification::assertSentTo([$anna, $ben], MemberInvitation::class);
        Notification::assertCount(2);
        expect(new MemberInvitation(Membership::query()->where('user_id', $anna->id)->sole()))->toBeInstanceOf(ShouldQueue::class)
            ->and(Membership::query()->where('organization_id', $this->org->id)->where('status', MembershipStatus::Invited->value)->count())->toBe(2)
            ->and($anna->groups()->withoutTenant()->pluck('groups.id')->all())->toBe([$this->crew->id])
            ->and(User::query()->where('email', 'bad')->exists())->toBeFalse()
            ->and(Activity::query()->where('event', 'member.invited')->count())->toBe(2);

        $summary = Activity::query()->where('event', 'member.bulk_invited')->sole();
        expect($summary->organization_id)->toBe($this->org->id)
            ->and($summary->causer_id)->toBe($this->owner->id)
            ->and($summary->properties->all())->toBe(['rows' => 3, 'invited' => 2, 'rejected' => 1, 'failed' => 0]);
    });

    it('shows the per-row report before sending', function (): void {
        actingInAdmin($this->owner, $this->org);
        $page = Livewire::test(ManageMembers::class)
            ->mountAction('importCsv')
            ->fillForm(['file' => csvFile("email,roles\nanna@example.com,viewer\nbad,viewer\n")])
            ->assertActionMounted('importCsv');
        // The modal is rendered client-side; render the wizard schema (both steps) with the uploaded file.
        expect($page->instance()->getSchema('mountedActionSchema0')?->toHtml())
            ->toContain(e(__('identity.csv_import.summary', ['valid' => 1, 'total' => 2])))
            ->toContain(e(__('identity.csv_import.errors.invalid_email')));

        $html = view('filament.members.csv-invitation-report', [
            'report' => app(BulkInvitationPlanner::class)->fromCsv($this->owner, $this->org, "email,name,roles\nanna@example.com,Anna,field_worker\nbad,,viewer\n"),
        ])->render();

        expect($html)->toContain(e(__('identity.csv_import.summary', ['valid' => 1, 'total' => 2])))
            ->toContain(e(__('identity.csv_import.errors.invalid_email')))
            ->toContain('anna@example.com')->toContain('Anna · '.e(__('roles.field_worker')))
            ->and(view('filament.members.csv-invitation-report', ['report' => null])->render())->toContain(e(__('identity.csv_import.errors.unreadable')));
        Notification::assertNothingSent();
    });

    it('sends nothing when no row is valid or the file is unusable', function (string $csv, string $key, array $params): void {
        actingInAdmin($this->owner, $this->org);

        Livewire::test(ManageMembers::class)->callAction('importCsv', ['file' => csvFile($csv)])->assertNotified(__('identity.csv_import.'.$key, $params));

        Notification::assertNothingSent();
        expect(Activity::query()->where('event', 'member.bulk_invited')->exists())->toBeFalse();
    })->with([
        'all rows invalid' => ["email,roles\nbad,viewer\n", 'nothing_to_send', []],
        'missing column' => ["email,name\na@example.com,A\n", 'errors.missing_columns', ['columns' => 'roles']],
    ]);

    it('keeps the guard for admins: escalating rows are skipped, the rest is sent', function (): void {
        $admin = memberOf($this->org, SystemRole::Admin);
        actingInAdmin($admin, $this->org);

        Livewire::test(ManageMembers::class)->callAction('importCsv', ['file' => csvFile("email,roles\nboss@example.com,owner\nok@example.com,designer\n")]);

        expect(User::query()->where('email', 'boss@example.com')->exists())->toBeFalse()
            ->and(User::query()->where('email', 'ok@example.com')->exists())->toBeTrue();
    });

    it('is not offered without members.manage', function (SystemRole $role): void {
        $user = memberOf($this->org, $role);
        actingInAdmin($user, $this->org);

        $this->get("/admin/{$this->org->slug}/members")->assertForbidden();
    })->with([SystemRole::Designer, SystemRole::Reviewer, SystemRole::Viewer]);
});
