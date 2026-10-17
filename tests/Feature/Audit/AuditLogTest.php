<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Redactor;
use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;

it('logs configuration changes once, with tenant, actor, IP and request id', function (): void {
    $org = makeOrganization();
    $owner = ownerOf($org);
    $this->actingAs($owner);
    clearAuditLog();

    $org->update(['name' => 'Renamed GmbH']);

    $entries = Activity::query()->where('subject_type', $org->getMorphClass())->where('event', 'updated')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->organization_id)->toBe($org->id)
        ->and($entries[0]->actor_type)->toBe('user')
        ->and($entries[0]->causer_id)->toBe($owner->id)
        ->and($entries[0]->ip_address)->toBe('127.0.0.1')
        ->and($entries[0]->attribute_changes['attributes']['name'] ?? null)->toBe('Renamed GmbH');
});

it('takes the organization from tenant-owned subjects', function (): void {
    $org = makeOrganization();
    app(TenantContext::class)->set($org);

    $group = Group::query()->create(['name' => 'Crew North']);

    $entry = Activity::query()->where('subject_type', $group->getMorphClass())->sole();
    expect($entry->organization_id)->toBe($org->id)->and($entry->event)->toBe('created');
});

it('logs authentication events', function (): void {
    $user = User::factory()->create(['password' => 'pw-123456']);

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'nope'])->assertUnprocessable();
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'pw-123456'])->assertOk();

    expect(Activity::query()->where('event', 'auth.failed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'auth.login')->where('causer_id', $user->id)->count())->toBe(1);
});

it('logs MFA enrolment exactly once per change', function (): void {
    $user = User::factory()->create();

    SharedAppAuthentication::make()->saveSecret($user, 'JBSWY3DPEHPK3PXP');
    SharedAppAuthentication::make()->saveSecret($user, null);

    expect(Activity::query()->where('event', 'auth.mfa_enabled')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'auth.mfa_disabled')->count())->toBe(1);
});

it('never stores secrets in audit properties or diffs', function (): void {
    $user = User::factory()->create();

    app(AuditLogger::class)->record('settings.updated', $user, [
        'smtp' => ['host' => 'mail.example.com', 'password' => 'hunter2', 'api_key' => 'k'],
        'webhook_secret' => 'abc',
    ]);
    $user->update(['password' => 'new-password-1']);
    $hash = $user->fresh()->getAuthPassword();

    $raw = Activity::query()->get()->toJson();
    expect($raw)->not->toContain('hunter2')->not->toContain('new-password-1')->not->toContain('"abc"')
        ->not->toContain($hash)
        ->and(Activity::query()->where('event', 'auth.password_changed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'settings.updated')->sole()->properties['smtp']['host'])->toBe('mail.example.com');
});

it('refuses to update or delete audit entries', function (): void {
    $entry = app(AuditLogger::class)->record('x.test');

    expect(fn () => $entry->update(['description' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('redacts nested secret keys', function (): void {
    expect(Redactor::redact(['a' => ['client_secret' => 's', 'ok' => 1], 'token' => null]))
        ->toBe(['a' => ['client_secret' => Redactor::MASK, 'ok' => 1], 'token' => null]);
});
