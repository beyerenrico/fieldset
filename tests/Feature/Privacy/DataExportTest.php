<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Privacy\Enums\DataExportStatus;
use App\Domain\Privacy\Exports\DataExportNotifications;
use App\Domain\Privacy\Exports\RequestDataExport;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* §11 Privacy, ADR 0039: user data export (ZIP of JSON) through a queued job with a signed, expiring download. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->notifier = new FakeNotifier;
    app()->instance(Notifier::class, $this->notifier);
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

/** @return array<string, string> file name => contents */
function zipContents(DataExport $export): array
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, Storage::disk($export->disk)->get((string) $export->path));
    $zip = new ZipArchive;
    $zip->open($path);
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);

    return $files;
}

it('exports a member\'s personal data as a ZIP of JSON, without secrets, and notifies the admin', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk();
    $this->s->worker->forceFill(['two_factor_secret' => encrypt('TOPSECRETTOTP')])->save();

    $export = $this->tenant->run($this->s->org, fn (): DataExport => app(RequestDataExport::class)->userData($this->s->owner, $this->s->worker, 'en', 'Europe/Berlin'));

    $export->refresh();
    expect($export->status)->toBe(DataExportStatus::Completed)->and($export->expires_at?->isFuture())->toBeTrue();
    $files = zipContents($export);
    expect(array_keys($files))->toEqualCanonicalizing(['profile.json', 'memberships.json', 'devices.json', 'submissions.json', 'audit-log.json', 'README.txt']);
    $profile = json_decode($files['profile.json'], true);
    $submissions = json_decode($files['submissions.json'], true);
    expect($profile['email'])->toBe($this->s->worker->email)
        ->and($submissions)->toHaveCount(1)->and($submissions[0]['uuid'])->toBe($uuid)
        ->and($submissions[0]['data']['customer_name'])->toBe('Beispiel GmbH')
        ->and($submissions[0]['attachments'][0]['kind'])->toBe('signature')
        ->and(json_decode($files['audit-log.json'], true))->not->toBeEmpty()
        ->and(implode("\n", $files))->not->toContain('TOPSECRETTOTP')->not->toContain($this->s->worker->getAuthPassword())
        ->and($this->notifier->hasSentTo($this->s->owner, NotificationEvent::ExportReady))->toBeTrue()
        ->and(Activity::query()->where('event', 'privacy.data_export_requested')->sole()->properties['self_service'])->toBeFalse();
});

it('lets users export their own data and admins export members, nobody else', function (): void {
    $designer = memberOf($this->s->org, SystemRole::Designer);
    $outsider = makeOrganization(name: 'Other GmbH');

    $this->tenant->run($this->s->org, function () use ($designer, $outsider): void {
        $own = app(RequestDataExport::class)->userData($designer, $designer, 'de', 'Europe/Berlin');
        expect($own->fresh()->status)->toBe(DataExportStatus::Completed)
            ->and(fn () => app(RequestDataExport::class)->userData($designer, $this->s->worker, 'de', 'UTC'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RequestDataExport::class)->userData($this->s->owner, ownerOf($outsider), 'de', 'UTC'))->toThrow(AuthorizationException::class);
    });
});

it('refuses a second export while one is being prepared', function (): void {
    $this->tenant->run($this->s->org, function (): void {
        DataExport::factory()->create(['requested_by' => $this->s->owner->id, 'kind' => 'user_data', 'subject_user_id' => $this->s->owner->id]);

        expect(fn () => app(RequestDataExport::class)->userData($this->s->owner, $this->s->owner, 'de', 'UTC'))->toThrow(ValidationException::class);
    });
});

it('serves the file only through the signed link, to the requester, until it expires', function (): void {
    $export = $this->tenant->run($this->s->org, fn (): DataExport => app(RequestDataExport::class)->userData($this->s->owner, $this->s->worker, 'en', 'UTC'));
    $url = DataExportNotifications::downloadUrl($export->refresh());
    $admin = memberOf($this->s->org, SystemRole::Admin);

    $this->actingAs($this->s->owner)->get($url)->assertOk()->assertDownload((string) $export->file_name);
    $this->actingAs($this->s->owner)->get(str_replace('signature=', 'signature=x', (string) $url))->assertForbidden();
    $this->actingAs($admin)->get($url)->assertNotFound();
    expect(Activity::query()->where('event', 'data_export.downloaded')->count())->toBe(1);

    $this->travel(8)->days();
    $fresh = URL::signedRoute('privacy.exports.download', ['organization' => $this->s->org->slug, 'export' => $export->id]);
    $this->actingAs($this->s->owner)->get($fresh)->assertStatus(410);
});

it('prunes exports and their files after the keep period', function (): void {
    $export = $this->tenant->run($this->s->org, fn (): DataExport => app(RequestDataExport::class)->userData($this->s->owner, $this->s->owner, 'en', 'UTC'));
    $path = (string) $export->refresh()->path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->travel(8)->days();
    $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

    expect(DataExport::query()->withoutTenant()->count())->toBe(0)->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('gives users their own audit details only and withholds submission data they may no longer see', function (): void {
    $uuid = FieldAppApi::readyDraft($this->s, $this->s->worker);
    FieldAppApi::submit($this->s, $this->s->worker, $uuid, 1)->assertOk();
    request()->server->set('REMOTE_ADDR', '198.51.100.7');
    $this->tenant->run($this->s->org, function (): void {
        $membership = Membership::query()->where('user_id', $this->s->worker->id)->where('organization_id', $this->s->org->id)->sole();
        app(AuditLogger::class)->record('member.roles_changed', $membership, ['roles' => ['field_worker']], $this->s->owner);
    });
    request()->server->set('REMOTE_ADDR', '127.0.0.1'); // the admin's address; the worker's own entries keep theirs
    DB::table('model_has_roles')->where('model_id', $this->s->worker->id)->where('model_type', $this->s->worker->getMorphClass())->delete();
    app(OrganizationPermissions::class)->forget();

    $export = $this->tenant->run($this->s->org, fn (): DataExport => app(RequestDataExport::class)->userData($this->s->worker, $this->s->worker, 'en', 'UTC'));

    $files = zipContents($export->refresh());
    $byAdmin = collect(json_decode($files['audit-log.json'], true))->firstWhere('event', 'member.roles_changed');
    $submission = json_decode($files['submissions.json'], true)[0];
    expect($byAdmin)->not->toHaveKey('ip_address')->not->toHaveKey('properties')->and($byAdmin['by_you'])->toBeFalse()
        ->and($files['audit-log.json'])->not->toContain('198.51.100.7')
        ->and($submission['data_withheld'])->toBeTrue()->and($submission['data'])->toBeNull()
        ->and($files['submissions.json'])->not->toContain('Beispiel GmbH');
});
