<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use Filament\Notifications\Notification as FilamentNotification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notifications\NotificationFixtures as N;

/* M4-T06: GET /notifications, POST /notifications/read (docs/api/field-app-v1.md), session only. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->worker = memberOf($this->org, SystemRole::FieldWorker);
    $this->other = memberOf($this->org, SystemRole::FieldWorker);
});

function notifyInApp(User $user, NotificationEvent $event, Organization $org, array $params = []): void
{
    app(Notifier::class)->send(N::message($event, $org, $params), [$user], [Notifier::CHANNEL_DATABASE]);
}

it('lists the user\'s notifications newest first with the unread count', function (): void {
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org, ['form' => 'First']);
    $this->travel(1)->minutes();
    notifyInApp($this->worker, NotificationEvent::ChangesRequested, $this->org, ['form' => 'Second']);
    Sanctum::actingAs($this->worker);

    $response = $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'total' => 2, 'unread' => 2])
        ->assertJsonPath('data.0.title', 'New form: Second')
        ->assertJsonPath('data.0.event', 'changes_requested')
        ->assertJsonPath('data.0.body', '“Second” is now available in the app.')
        ->assertJsonPath('data.0.url', '/app/forms')
        ->assertJsonPath('data.0.organization_id', $this->org->id)
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.1.title', 'New form: First');

    expect(array_keys($response->json('data.0')))->toBe(['id', 'event', 'title', 'body', 'url', 'organization_id', 'read_at', 'created_at'])
        ->and($response->json('data.0.id'))->toBe(N::stored($this->worker)->first()->id);
});

it('paginates', function (): void {
    foreach (range(1, 26) as $i) {
        notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org, ['form' => "Form {$i}"]);
    }
    Sanctum::actingAs($this->worker);

    $this->getJson('/api/v1/notifications?page=2')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.total', 26)
        ->assertJsonPath('meta.unread', 26);
    $this->getJson('/api/v1/notifications?page=0')->assertStatus(422)->assertJsonPath('error.details.0.pointer', '/page');
});

it('lists notifications from all the user\'s organizations without an organization header', function (): void {
    $second = makeOrganization(name: 'Second GmbH');
    app(AddMember::class)->handle($second, $this->worker, [SystemRole::FieldWorker]);
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org);
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $second);
    Sanctum::actingAs($this->worker);

    $ids = $this->getJson('/api/v1/notifications')->assertOk()->json('data.*.organization_id');

    expect($ids)->toEqualCanonicalizing([$this->org->id, $second->id]);
});

it('never shows other users\' notifications or admin-only database notifications', function (): void {
    notifyInApp($this->other, NotificationEvent::FormAvailable, $this->org, ['form' => 'Secret']);
    FilamentNotification::make()->title('PDF import finished')->sendToDatabase($this->worker);
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org, ['form' => 'Mine']);
    Sanctum::actingAs($this->worker);

    $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'New form: Mine')
        ->assertJsonPath('meta.unread', 1);
});

it('marks the given notifications as read and returns the unread count', function (): void {
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org);
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org);
    [$first, $second] = N::stored($this->worker)->all();
    Sanctum::actingAs($this->worker);

    $this->postJson('/api/v1/notifications/read', ['ids' => [$first->id]])->assertOk()->assertJsonPath('data', ['unread' => 1]);

    expect($first->fresh()->read_at)->not->toBeNull()->and($second->fresh()->read_at)->toBeNull();
    $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 1)
        ->assertJsonPath('data.0.read_at', $first->fresh()->read_at->toIso8601String());
});

it('marks all as read when no ids are given', function (?array $body): void {
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org);
    notifyInApp($this->worker, NotificationEvent::ReviewAssigned, $this->org);
    notifyInApp($this->other, NotificationEvent::FormAvailable, $this->org);
    Sanctum::actingAs($this->worker);

    $this->postJson('/api/v1/notifications/read', $body ?? [])->assertOk()->assertJsonPath('data.unread', 0);

    expect(N::stored($this->other)->first()->read_at)->toBeNull();
})->with([[null], [['ids' => null]]]);

it('ignores notification ids of other users', function (): void {
    notifyInApp($this->other, NotificationEvent::FormAvailable, $this->org);
    notifyInApp($this->worker, NotificationEvent::FormAvailable, $this->org);
    $foreign = N::stored($this->other)->first();
    Sanctum::actingAs($this->worker);

    $this->postJson('/api/v1/notifications/read', ['ids' => [$foreign->id]])->assertOk()->assertJsonPath('data.unread', 1);

    expect($foreign->fresh()->read_at)->toBeNull();
});

it('validates the read request', function (array $body, string $pointer): void {
    Sanctum::actingAs($this->worker);

    $this->postJson('/api/v1/notifications/read', $body)->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.0.pointer', $pointer);
})->with([
    'ids not a list' => [['ids' => 'all'], '/ids'],
    'id not a uuid' => [['ids' => ['nope']], '/ids/0'],
]);

it('requires a session', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $this->postJson('/api/v1/notifications/read')->assertUnauthorized();
});
