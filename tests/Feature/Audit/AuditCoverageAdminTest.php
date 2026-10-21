<?php

declare(strict_types=1);

use App\Domain\Audit\Export\ExportAuditLog;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Query\AuditLogFilters;
use App\Domain\Documents\Branding\UpdateBranding;
use App\Domain\Identity\Actions\AcceptInvitation;
use App\Domain\Identity\Actions\ChangeMemberRoles;
use App\Domain\Identity\Actions\DeactivateUser;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Actions\SetDefaultGroup;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Devices\RegisterDevice;
use App\Domain\Identity\Devices\RevokeDevice;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Admin\DeleteIdentityProvider;
use App\Domain\Identity\Sso\Admin\SaveClaimMapping;
use App\Domain\Identity\Sso\Admin\SaveIdentityProvider;
use App\Domain\Identity\Sso\Admin\SetSsoEnforcement;
use App\Domain\Identity\Sso\Domains\AddSsoDomain;
use App\Domain\Identity\Sso\Domains\RemoveSsoDomain;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Mail\Actions\SaveMailSettings;
use App\Domain\Mail\Actions\SendTestMail;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use App\Domain\Platform\Impersonation\EndImpersonation;
use App\Domain\Platform\Impersonation\StartImpersonation;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Exports\RequestDataExport;
use App\Domain\Privacy\Retention\SaveRetentionPolicy;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Exports\StartExport;
use App\Domain\Reports\Filters\ReportFilters;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuditTrail;
use Tests\Support\FieldAppScenario;

require_once __DIR__.'/../Platform/Support.php';

/*
 * §8.11 AC, part 3 (users, SSO, mail settings, settings changes, exports, impersonation): each action writes
 * exactly the listed entry; secrets never reach the audit log.
 */

beforeEach(function (): void {
    AuditTrail::reset();
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    actingInAdmin($this->s->owner, $this->s->org);
});

afterEach(function (): void {
    expect(AuditTrail::seen())->not->toContain('Sup3r-Secret!')->not->toContain('Smtp-Secret-1')->not->toContain('Invite-Passw0rd!');
});

it('writes one entry per member and account action', function (): void {
    $owner = $this->s->owner;
    $group = Group::query()->create(['name' => 'Crew']);
    $membership = null;

    expect(AuditTrail::of(function () use ($owner, &$membership): void {
        $membership = app(InviteMember::class)->handle($owner, $this->s->org, 'neu@acme.example', 'Neu', [SystemRole::FieldWorker]);
    }))->toBe(['member.invited']);
    /** @var Membership $membership */
    $user = $membership->user;

    expect(AuditTrail::of(fn () => app(AcceptInvitation::class)->handle($membership->refresh(), 'Invite-Passw0rd!')))->toBe(['member.joined'])
        ->and(AuditTrail::of(fn () => app(ChangeMemberRoles::class)->handle($owner, $membership->refresh(), ['reviewer'])))->toBe(['member.roles_changed'])
        ->and(AuditTrail::of(fn () => app(SyncMemberGroups::class)->handle($this->s->org, $user, [$group->id], $owner)))->toBe(['member.groups_changed'])
        ->and(AuditTrail::of(fn () => app(SetDefaultGroup::class)->handle($membership->refresh(), $group->id, $owner)))->toBe(['member.default_group_changed'])
        ->and(AuditTrail::of(fn () => $user->refresh()->update(['name' => 'Neu Name'])))->toBe(['updated'])
        ->and(AuditTrail::of(fn () => app(RegisterDevice::class)->handle($user, FieldAppScenario::uuid(), 'Phone', 'android')))->toBe(['device.registered'])
        ->and(AuditTrail::of(fn () => app(RevokeDevice::class)->handle(Device::query()->where('user_id', $user->id)->sole(), $owner)))->toBe(['device.revoked'])
        ->and(AuditTrail::of(fn () => app(SetMembershipStatus::class)->handle($membership->refresh(), MembershipStatus::Deactivated, $owner)))->toBe(['member.status_changed'])
        ->and(AuditTrail::of(fn () => app(DeactivateUser::class)->handle($user->refresh(), $owner)))->toBe(['user.deactivated']);
});

it('writes one entry per SSO provider, domain, mapping and enforcement change', function (): void {
    $owner = $this->s->owner;
    $owner->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $config = ['flavor' => 'generic', 'issuer' => 'https://idp.acme.example/realms/acme/', 'client_id' => 'fieldset'];
    $provider = null;

    expect(AuditTrail::of(function () use ($config, $owner, &$provider): void {
        $provider = app(SaveIdentityProvider::class)->handle($this->s->org, IdentityProviderType::Oidc, 'Acme SSO', $config, 'Sup3r-Secret!', $owner);
    }))->toBe(['sso.provider_created']);
    /** @var IdentityProvider $provider */
    expect(AuditTrail::of(fn () => app(SaveIdentityProvider::class)->handle($this->s->org, IdentityProviderType::Oidc, 'Acme Login', $config, null, $owner, $provider->uuid)))
        ->toBe(['sso.provider_updated'])
        ->and(AuditTrail::of(fn () => app(AddSsoDomain::class)->handle($this->s->org, 'acme.example', $owner)))->toBe(['sso.domain_added']);

    $reviewer = Role::query()->where('organization_id', $this->s->org->id)->where('name', 'reviewer')->sole();
    expect(AuditTrail::of(fn () => app(SaveClaimMapping::class)->handle($provider, ['claim' => '', 'value' => 'reviewers', 'role_id' => $reviewer->id], $owner)))->toBe(['created']);

    $provider->forceFill(['status' => IdentityProviderStatus::Active])->save();
    SsoDomain::query()->update(['verified_at' => now()]);
    $pending = app(AddSsoDomain::class)->handle($this->s->org, 'acme-other.example', $owner);
    expect(AuditTrail::of(fn () => app(SetSsoEnforcement::class)->handle($provider->refresh(), true, $owner->refresh(), $owner)))->toBe(['sso.enforcement_changed'])
        ->and(AuditTrail::of(fn () => app(RemoveSsoDomain::class)->handle($pending, $owner)))->toBe(['sso.domain_removed']);

    app(SetSsoEnforcement::class)->handle($provider->refresh(), false, null, $owner);
    expect(AuditTrail::of(fn () => app(DeleteIdentityProvider::class)->handle($provider->refresh(), $owner)))->toBe(['sso.provider_deleted']);
});

it('writes one entry per mail settings change and test mail', function (): void {
    config(['messaging.smtp.allow_private_hosts' => true]); // no DNS in tests
    $input = ['smtp_enabled' => true, 'smtp_host' => 'localhost', 'smtp_port' => 587, 'smtp_encryption' => 'starttls',
        'smtp_username' => 'mailer', 'smtp_password' => 'Smtp-Secret-1', 'smtp_from_address' => 'office@example.com'];

    expect(AuditTrail::of(fn () => app(SaveMailSettings::class)->handle($input, $this->s->owner)))->toBe(['mail_settings.created'])
        ->and(AuditTrail::of(fn () => app(SaveMailSettings::class)->handle([...$input, 'sender_name' => 'Acme Office'], $this->s->owner)))->toBe(['mail_settings.updated'])
        ->and(AuditTrail::of(function (): void {
            Queue::fake(); // delivery itself is not audited (no document); the request is
            app(SendTestMail::class)->handle($this->s->owner);
        }))->toBe(['mail_settings.test_sent']);
});

it('writes one entry per settings change', function (): void {
    expect(AuditTrail::of(fn () => $this->s->org->update(['name' => 'Acme Service GmbH', 'timezone' => 'Europe/Vienna'])))->toBe(['updated'])
        ->and(AuditTrail::of(fn () => app(UpdateBranding::class)->handle($this->s->org->refresh(), ['primary_color' => '#112233'])))->toBe(['updated'])
        ->and(AuditTrail::of(fn () => app(ChannelPreferences::class)->update($this->s->owner, ['form_available' => ['mail' => true]])))->toBe(['notification_preferences.updated'])
        ->and(AuditTrail::of(fn () => app(ChannelPreferences::class)->update($this->s->owner, ['form_available' => ['mail' => true]])))->toBe([])
        ->and(AuditTrail::of(fn () => app(SaveRetentionPolicy::class)->handle($this->s->form, 24, RetentionAction::Anonymize, $this->s->owner)))->toBe(['privacy.retention_changed']);
});

it('writes one entry per export: report, form definition, audit log and personal data', function (): void {
    expect(AuditTrail::of(fn () => app(StartExport::class)->handle($this->s->owner, $this->s->form, ExportFormat::Csv, new ReportFilters, 'Europe/Berlin', null, 'en')))
        ->toBe(['report.exported'])
        ->and(AuditTrail::of(fn () => app(ExportAuditLog::class)->handle($this->s->owner, new AuditLogFilters, 'en')))->toBe(['audit.exported'])
        ->and(AuditTrail::of(fn () => app(RequestDataExport::class)->userData($this->s->owner, $this->s->worker, 'en', 'UTC')))->toBe(['privacy.data_export_requested']);
});

it('writes one entry each for an impersonation start and end on /platform', function (): void {
    $operator = platformOperator(); // tests/Feature/Platform/Support.php, shared with ImpersonationTest
    actingAsPlatformOperator($operator);
    $impersonation = null;

    expect(AuditTrail::of(function () use ($operator, &$impersonation): void {
        $impersonation = app(StartImpersonation::class)->handle($operator, $this->s->org, $this->s->owner, 'Ticket 4711: the workflow does not start');
    }))->toBe(['platform.impersonation_started'])
        ->and(auth()->id())->toBe($this->s->owner->id)
        ->and(AuditTrail::of(fn () => app(EndImpersonation::class)->handle($impersonation, EndImpersonation::BY_OPERATOR)))->toBe(['platform.impersonation_ended'])
        ->and(auth()->id())->toBe($operator->id)
        ->and(Activity::query()->whereIn('event', ['platform.impersonation_started', 'platform.impersonation_ended'])->pluck('organization_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all())
        ->toBe([$this->s->org->id]);
});
