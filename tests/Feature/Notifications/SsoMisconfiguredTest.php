<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use Tests\Feature\Notifications\NotificationFixtures as N;

/* M7 (§8.9, ADR 0036): sso_misconfigured reaches Owners/Admins (members.manage) at most once per provider and reason per window. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->tenant = app(TenantContext::class);
    $this->provider = $this->tenant->run($this->org, fn (): IdentityProvider => IdentityProvider::factory()->create(['name' => 'Acme Entra']));
    $this->tenant->clear();
});

function ssoMisconfiguredFakeNotifier(): FakeNotifier
{
    $fake = new FakeNotifier;
    app()->instance(Notifier::class, $fake);

    return $fake;
}

it('notifies the members who manage users and SSO, and nobody else', function (): void {
    $fake = ssoMisconfiguredFakeNotifier();
    $admin = memberOf($this->org, SystemRole::Admin);
    $designer = memberOf($this->org, SystemRole::Designer);
    memberOf($this->org, SystemRole::Viewer);
    $suspendedAdmin = memberOf($this->org, SystemRole::Admin);
    Membership::query()->where('user_id', $suspendedAdmin->id)->update(['status' => MembershipStatus::Deactivated->value]);
    $teamLead = memberOf($this->org, SystemRole::Reviewer);
    $this->tenant->run($this->org, fn () => $teamLead->givePermissionTo(Permission::MembersManage->value));
    $foreignAdmin = memberOf(makeOrganization(name: 'Other'), SystemRole::Admin);
    $custom = Role::create(['name' => 'IT', 'guard_name' => 'web', 'organization_id' => $this->org->id]);
    $custom->givePermissionTo(Permission::MembersManage->value);
    $it = memberOf($this->org, SystemRole::FieldWorker);
    $this->tenant->run($this->org, fn () => $it->assignRole($custom));
    $this->tenant->clear();

    app(SsoMisconfigurationReporter::class)->report($this->provider, 'break_glass_invalid', 'boss@acme.example has no MFA');

    $sent = $fake->sentFor(NotificationEvent::SsoMisconfigured);
    expect($sent)->toHaveCount(1)
        ->and(collect($sent[0]['recipients'])->sort()->values()->all())->toBe(collect([$this->owner->id, $admin->id, $teamLead->id, $it->id])->sort()->values()->all())
        ->and($sent[0]['recipients'])->not->toContain($designer->id, $suspendedAdmin->id, $foreignAdmin->id)
        ->and($sent[0]['channels'])->toBeNull()
        ->and($sent[0]['message'])
        ->titleKey->toBe('notifications.messages.sso_misconfigured.title')
        ->bodyKey->toBe('notifications.messages.sso_misconfigured.reasons.break_glass_invalid')
        ->organizationId->toBe($this->org->id)
        ->status->toBe('danger')
        ->and($sent[0]['message']->params)->toMatchArray(['provider' => 'Acme Entra', 'detail' => 'boss@acme.example has no MFA'])
        ->and($sent[0]['message']->url)->toEndWith('/admin/'.$this->org->slug.'/sso');
});

it('notifies at most once per provider and reason in the window and audits each notification', function (): void {
    config(['sso.notify_every' => 3600]);
    $fake = ssoMisconfiguredFakeNotifier();
    $reporter = app(SsoMisconfigurationReporter::class);

    $reporter->report($this->provider, 'jwks', 'JWKS unreachable');
    $reporter->report($this->provider, 'jwks', 'JWKS still unreachable');
    $reporter->report($this->provider, 'domain_unverified', 'acme.example');
    expect($fake->sentFor(NotificationEvent::SsoMisconfigured))->toHaveCount(2);

    $this->travel(3601)->seconds();
    $reporter->report($this->provider, 'jwks', 'JWKS unreachable again');

    $entries = Activity::query()->where('event', 'sso.misconfigured')->orderBy('id')->get();
    expect($fake->sentFor(NotificationEvent::SsoMisconfigured))->toHaveCount(3)
        ->and($entries->pluck('properties.reason')->all())->toBe(['jwks', 'domain_unverified', 'jwks'])
        ->and($entries->first()->organization_id)->toBe($this->org->id)
        ->and((int) $entries->first()->subject_id)->toBe($this->provider->id);
});

it('uses the generic text for reasons without their own', function (): void {
    $fake = ssoMisconfiguredFakeNotifier();

    app(SsoMisconfigurationReporter::class)->report($this->provider, 'Certificate Expiring', 'in 3 days');

    expect($fake->sent[0]['message']->bodyKey)->toBe('notifications.messages.sso_misconfigured.reasons.other')
        ->and($fake->sent[0]['message']->params['reason'])->toBe('certificate_expiring');
});

it('delivers in-app and by email in the recipient\'s language by default', function (): void {
    $this->owner->forceFill(['locale' => 'de'])->save();

    app(SsoMisconfigurationReporter::class)->report($this->provider, 'domain_unverified', 'acme.example');

    $stored = N::stored($this->owner)->sole();
    expect($stored->data['event'])->toBe('sso_misconfigured')
        ->and($stored->data['text']['title'])->toBe(__('notifications.messages.sso_misconfigured.title', ['provider' => 'Acme Entra'], 'de'))
        ->and($stored->data['text']['body'])->toContain('acme.example')
        ->and(N::mailRecipients())->toBe([$this->owner->email]);
});

it('never throws when the notifier fails, and tries again next time', function (): void {
    $broken = new class implements Notifier
    {
        public int $calls = 0;

        public function send(NotificationMessage $message, iterable $recipients, ?array $channels = null): void
        {
            $this->calls++;
            throw new RuntimeException('queue down');
        }
    };
    app()->instance(Notifier::class, $broken);

    app(SsoMisconfigurationReporter::class)->report($this->provider, 'jwks', 'x');
    app(SsoMisconfigurationReporter::class)->report($this->provider, 'jwks', 'x');

    expect($broken->calls)->toBe(2)->and(Activity::query()->where('event', 'sso.misconfigured')->count())->toBe(0);
});
