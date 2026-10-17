<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Domain\Privacy\Erasure\EraseUser;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* §11 Privacy, ADR 0039: erasure anonymizes the account, keeps required records attributed to "Deleted user". */

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    $this->tenant = app(TenantContext::class);
});

it('erases a member: personal data gone, access ended, submissions kept and re-attributed', function (): void {
    $worker = $this->s->worker;
    $email = $worker->email;
    $uuid = FieldAppApi::readyDraft($this->s, $worker);
    FieldAppApi::submit($this->s, $worker, $uuid, 1)->assertOk();
    $this->withHeaders(apiHeaders($worker, $this->s->org))->postJson('/api/v1/devices', ['device_uuid' => FieldAppScenario::uuid(), 'name' => "Max's phone"])->assertSuccessful();
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $worker->id, 'payload' => '', 'last_activity' => time()]);

    $this->tenant->run($this->s->org, fn () => app(EraseUser::class)->byAdmin($this->s->owner, $worker));

    $fresh = User::query()->findOrFail($worker->id);
    expect($fresh->name)->toBe(AccountScrubber::ERASED_NAME)->and($fresh->email)->not->toBe($email)
        ->and($fresh->status)->toBe(UserStatus::Deactivated)->and(EraseUser::isErased($fresh))->toBeTrue()
        ->and($fresh->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $worker->id)->exists())->toBeFalse()
        ->and(Device::query()->where('user_id', $worker->id)->whereNotNull('name')->exists())->toBeFalse()
        ->and(Device::query()->where('user_id', $worker->id)->whereNull('wipe_requested_at')->exists())->toBeFalse()
        ->and(Membership::query()->where('user_id', $worker->id)->sole()->status)->toBe(MembershipStatus::Deactivated)
        ->and(DB::table('model_has_roles')->where('model_id', $worker->id)->where('model_type', $worker->getMorphClass())->exists())->toBeFalse()
        ->and($this->tenant->run($this->s->org, fn () => Submission::query()->findOrFail($uuid)->submitted_by))->toBe($worker->id);

    $entry = Activity::query()->where('event', 'privacy.user_erased')->sole();
    expect($entry->causer_id)->toBe($this->s->owner->id)->and($entry->properties['self_service'])->toBeFalse()
        ->and(Activity::query()->where('id', '>', $entry->id - 1)->get()->toJson())->not->toContain($email)
        ->and(Activity::query()->where('subject_type', $worker->getMorphClass())->where('event', 'updated')->where('id', '>=', $entry->id - 5)->exists())->toBeFalse();

    $this->postJson('/auth/login', ['email' => $email, 'password' => 'password'])->assertUnprocessable();
});

it('refuses to erase the last Owner, also in self-service', function (): void {
    $owner = $this->s->owner;

    expect(fn () => app(EraseUser::class)->self($owner))->toThrow(ValidationException::class);

    $second = memberOf($this->s->org, SystemRole::Owner);
    app(EraseUser::class)->self($owner);
    expect(EraseUser::isErased($owner))->toBeTrue()->and(EraseUser::isErased($second))->toBeFalse()
        ->and(Activity::query()->where('event', 'privacy.user_erased')->sole()->properties['self_service'])->toBeTrue();
});

it('lets an admin erase only accounts that belong to no other organization, and Owners only by Owners', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    $shared = memberOf($this->s->org, SystemRole::FieldWorker);
    app(AddMember::class)->handle($other, $shared, [SystemRole::FieldWorker]);
    $admin = memberOf($this->s->org, SystemRole::Admin);
    $coOwner = memberOf($this->s->org, SystemRole::Owner);

    $this->tenant->run($this->s->org, function () use ($shared, $admin, $coOwner): void {
        expect(fn () => app(EraseUser::class)->byAdmin($this->s->owner, $shared))->toThrow(ValidationException::class)
            ->and(fn () => app(EraseUser::class)->byAdmin($admin, $coOwner))->toThrow(ValidationException::class)
            ->and(fn () => app(EraseUser::class)->byAdmin($admin, $admin))->toThrow(ValidationException::class)
            ->and(fn () => app(EraseUser::class)->byAdmin($this->s->worker, $admin))->toThrow(AuthorizationException::class);

        app(EraseUser::class)->byAdmin($this->s->owner, $coOwner);
    });

    expect(EraseUser::isErased($coOwner))->toBeTrue()->and(EraseUser::isErased($shared))->toBeFalse();
});

it('is idempotent: erasing twice writes one audit entry', function (): void {
    $this->tenant->run($this->s->org, function (): void {
        app(EraseUser::class)->byAdmin($this->s->owner, $this->s->worker);
        app(EraseUser::class)->byAdmin($this->s->owner, $this->s->worker->fresh());
    });

    expect(Activity::query()->where('event', 'privacy.user_erased')->count())->toBe(1);
});

it('removes pending password resets and mail sent to the erased address', function (): void {
    $email = $this->s->worker->email;
    DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'x', 'created_at' => now()]);
    $this->tenant->run($this->s->org, fn () => MailMessage::factory()->create(['recipient_email' => $email]));

    $this->tenant->run($this->s->org, fn () => app(EraseUser::class)->byAdmin($this->s->owner, $this->s->worker));

    expect(DB::table('password_reset_tokens')->where('email', $email)->exists())->toBeFalse()
        ->and(DB::table('mail_messages')->where('recipient_email', $email)->exists())->toBeFalse();
});

it('refuses an admin erasure while the account has any membership elsewhere, also a deactivated one', function (): void {
    $other = makeOrganization(name: 'Other GmbH');
    $membership = app(AddMember::class)->handle($other, $this->s->worker, [SystemRole::FieldWorker]);
    $membership->forceFill(['status' => MembershipStatus::Deactivated])->save();

    expect(fn () => $this->tenant->run($this->s->org, fn () => app(EraseUser::class)->byAdmin($this->s->owner, $this->s->worker)))
        ->toThrow(ValidationException::class);
});
