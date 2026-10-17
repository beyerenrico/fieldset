<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use Laravel\Sanctum\Sanctum;

/* M4-T06: GET|PUT /me/notification-preferences (docs/api/field-app-v1.md), session only, user-level. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->worker = memberOf($this->org, SystemRole::FieldWorker);
});

/** @return array<string, array{database: bool, mail: bool}> */
function preferencesByEvent(array $events): array
{
    $byEvent = [];
    foreach ($events as $entry) {
        $byEvent[$entry['event']] = $entry['channels'];
    }

    return $byEvent;
}

it('returns every event with the default channels', function (): void {
    Sanctum::actingAs($this->worker);

    $events = $this->getJson('/api/v1/me/notification-preferences')->assertOk()->json('data.events');

    expect(array_column($events, 'event'))->toBe(array_map(fn (NotificationEvent $e): string => $e->value, NotificationEvent::cases()))
        ->and(preferencesByEvent($events))->toBe([
            'review_assigned' => ['database' => true, 'mail' => true],
            'changes_requested' => ['database' => true, 'mail' => true],
            'submission_approved' => ['database' => true, 'mail' => true],
            'workflow_failed' => ['database' => true, 'mail' => true],
            'form_available' => ['database' => true, 'mail' => false],
            'workflow_notice' => ['database' => true, 'mail' => false],
            'export_ready' => ['database' => true, 'mail' => true],
            'sso_misconfigured' => ['database' => true, 'mail' => true],
        ]);
});

it('applies partial updates and answers with the full settings', function (): void {
    Sanctum::actingAs($this->worker);

    $events = $this->putJson('/api/v1/me/notification-preferences', ['events' => [
        ['event' => 'review_assigned', 'channels' => ['mail' => false]],
        ['event' => 'form_available', 'channels' => ['mail' => true, 'database' => false]],
    ]])->assertOk()->json('data.events');

    expect(preferencesByEvent($events))
        ->review_assigned->toBe(['database' => true, 'mail' => false])
        ->form_available->toBe(['database' => false, 'mail' => true])
        ->changes_requested->toBe(['database' => true, 'mail' => true])
        ->and(app(ChannelPreferences::class)->enabledChannels([$this->worker->id], NotificationEvent::ReviewAssigned))
        ->toBe([$this->worker->id => ['database']]);

    $this->putJson('/api/v1/me/notification-preferences', ['events' => [['event' => 'review_assigned', 'channels' => ['database' => false]]]])
        ->assertOk()->assertJsonPath('data.events.0', ['event' => 'review_assigned', 'channels' => ['database' => false, 'mail' => false]]);
    expect(NotificationPreference::query()->where('user_id', $this->worker->id)->count())->toBe(4);
});

it('changes only the caller\'s settings', function (): void {
    $other = memberOf($this->org, SystemRole::FieldWorker);
    Sanctum::actingAs($this->worker);

    $this->putJson('/api/v1/me/notification-preferences', ['events' => [['event' => 'review_assigned', 'channels' => ['mail' => false]]]])->assertOk();

    expect(app(ChannelPreferences::class)->matrix($other)['review_assigned'])->toBe(['database' => true, 'mail' => true]);
});

it('rejects unknown events, channels and non-boolean values', function (array $body, string $pointer): void {
    Sanctum::actingAs($this->worker);

    $this->putJson('/api/v1/me/notification-preferences', $body)->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.0.pointer', $pointer);

    expect(NotificationPreference::query()->count())->toBe(0);
})->with([
    'unknown event' => [['events' => [['event' => 'not_an_event', 'channels' => ['mail' => true]]]], '/events/0/event'],
    'unknown channel' => [['events' => [['event' => 'review_assigned', 'channels' => ['push' => true]]]], '/events/0/channels'],
    'not a boolean' => [['events' => [['event' => 'review_assigned', 'channels' => ['mail' => 'yes']]]], '/events/0/channels/mail'],
    'number instead of boolean' => [['events' => [['event' => 'review_assigned', 'channels' => ['mail' => 1]]]], '/events/0/channels/mail'],
    'missing events' => [[], '/events'],
    'events not a list' => [['events' => ['review_assigned' => ['mail' => true]]], '/events'],
]);

it('requires a session', function (): void {
    $this->getJson('/api/v1/me/notification-preferences')->assertUnauthorized();
    $this->putJson('/api/v1/me/notification-preferences', ['events' => []])->assertUnauthorized();
});
