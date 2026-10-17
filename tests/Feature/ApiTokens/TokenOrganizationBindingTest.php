<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\DeactivateUser;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Tokens\Actions\RevokeApiToken;
use App\Domain\Identity\Tokens\TokenAbility as A;
use App\Domain\Notifications\Delivery\StoredNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\ApiTokens\Support\TokenApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
    $this->other = makeOrganization(name: 'Zweite GmbH');
    app(AddMember::class)->handle($this->other, $this->s->owner, [SystemRole::Owner]);
});

function storeNotificationFor(object $user, ?int $organizationId, string $title): void
{
    DatabaseNotification::query()->forceCreate([
        'id' => (string) Str::uuid(), 'type' => StoredNotification::TYPE,
        'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->getKey(),
        'data' => ['event' => 'task.assigned', 'organization_id' => $organizationId, 'text' => ['title' => $title, 'body' => '']],
    ]);
}

it('binds the token to its organization even when the user belongs to both', function (): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead]);

    $this->withHeaders(TokenApi::headers($plain, $this->other))->getJson('/api/v1/forms')
        ->assertForbidden()->assertJsonPath('error.code', 'organization_forbidden');
    $this->withHeaders(TokenApi::headers($plain, $this->s->org))->getJson('/api/v1/forms')
        ->assertOk()->assertJsonPath('data.0.id', $this->s->form->id);
});

it('implies the organization of the token when the header is missing', function (): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead]);

    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms')
        ->assertOk()->assertJsonPath('data.0.id', $this->s->form->id);
});

it('still requires the header for Field App sessions', function (): void {
    $headers = apiHeaders($this->s->owner, $this->s->org);
    unset($headers['X-Organization-Id']);

    $this->withHeaders($headers)->getJson('/api/v1/forms')
        ->assertStatus(400)->assertJsonPath('error.code', 'organization_required');
});

it('shows a token only the notifications of its organization', function (): void {
    storeNotificationFor($this->s->owner, $this->s->org->id, 'Own organization');
    storeNotificationFor($this->s->owner, $this->other->id, 'Other organization');
    storeNotificationFor($this->s->owner, null, 'Without organization');
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::NotificationsRead]);

    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/notifications')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Own organization')
        ->assertJsonPath('meta.unread', 1);
    $this->withHeaders(TokenApi::headers($plain))->postJson('/api/v1/notifications/read')->assertOk();
    expect(DatabaseNotification::query()->whereNull('read_at')->count())->toBe(2);
});

it('refuses tokens that are not bound to an organization', function (): void {
    $token = $this->s->owner->createToken('unbound', [A::FormsRead->value, A::NotificationsRead->value]);

    $this->withHeaders(TokenApi::headers($token->plainTextToken, $this->s->org))->getJson('/api/v1/forms')
        ->assertForbidden()->assertJsonPath('error.code', 'token_unbound');
    $this->withHeaders(TokenApi::headers($token->plainTextToken))->getJson('/api/v1/notifications')
        ->assertForbidden()->assertJsonPath('error.code', 'token_unbound');
});

it('stops accepting a token once it expired', function (): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead], now()->addDay());

    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms')->assertOk();
    $this->travel(25)->hours();
    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms')
        ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('stops accepting a revoked token', function (): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead]);
    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms')->assertOk();

    app(RevokeApiToken::class)->handle(PersonalAccessToken::findToken($plain), $this->s->owner);

    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms')
        ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('deletes only the tokens of the organization when a membership is deactivated', function (): void {
    $admin = memberOf($this->s->org, SystemRole::Admin);
    app(AddMember::class)->handle($this->other, $admin, [SystemRole::Admin]);
    $here = TokenApi::issue($admin, $this->s->org, [A::FormsRead]);
    $there = TokenApi::issue($admin, $this->other, [A::FormsRead]);
    $this->withHeaders(TokenApi::headers($here))->getJson('/api/v1/forms')->assertOk();   // first_used_at set

    $membership = Membership::query()->where('organization_id', $this->s->org->id)->where('user_id', $admin->id)->firstOrFail();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, $this->s->owner);

    expect(PersonalAccessToken::findToken($here))->toBeNull()
        ->and(PersonalAccessToken::findToken($there))->not->toBeNull();
    $this->withHeaders(TokenApi::headers($here))->getJson('/api/v1/forms')->assertUnauthorized();
    $this->withHeaders(TokenApi::headers($there))->getJson('/api/v1/forms')->assertOk();
});

it('deletes every token when the user is deactivated', function (): void {
    $admin = memberOf($this->s->org, SystemRole::Admin);
    app(AddMember::class)->handle($this->other, $admin, [SystemRole::Admin]);
    $here = TokenApi::issue($admin, $this->s->org, [A::FormsRead]);
    $there = TokenApi::issue($admin, $this->other, [A::FormsRead]);

    app(DeactivateUser::class)->handle($admin, $this->s->owner);

    expect(PersonalAccessToken::query()->where('tokenable_id', $admin->id)->count())->toBe(0);
    $this->withHeaders(TokenApi::headers($here))->getJson('/api/v1/forms')->assertUnauthorized();
    $this->withHeaders(TokenApi::headers($there))->getJson('/api/v1/forms')->assertUnauthorized();
});

it('keeps the Field App session flow unchanged', function (): void {
    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson('/api/v1/me')->assertOk();
    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->getJson('/api/v1/forms')
        ->assertOk()->assertJsonPath('data.0.id', $this->s->form->id);
    $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))
        ->putJson('/api/v1/me/notification-preferences', ['events' => [['event' => 'review_assigned', 'channels' => ['mail' => false]]]])
        ->assertOk();
});

it('gives a real cookie session full access as before', function (): void {
    $this->actingAs($this->s->worker, 'web');

    $this->withHeaders(['X-Organization-Id' => (string) $this->s->org->id, 'Accept' => 'application/json'])
        ->getJson('/api/v1/forms')->assertOk()->assertJsonPath('data.0.id', $this->s->form->id);
    $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/v1/me')->assertOk();
});
