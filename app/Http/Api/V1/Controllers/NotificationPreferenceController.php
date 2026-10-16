<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET|PUT /me/notification-preferences` (§8.9): the user's channels per event. Session only; the
 * settings are user-level and apply in every organization. PUT accepts partial updates.
 */
final readonly class NotificationPreferenceController
{
    public function __construct(private ChannelPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(['events' => $this->events($this->user($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'events' => ['required', 'array', 'list', 'max:50'],
            'events.*' => ['required', 'array:event,channels'],
            'events.*.event' => ['required', 'string', Rule::in(array_map(fn (NotificationEvent $e): string => $e->value, NotificationEvent::cases()))],
            'events.*.channels' => ['present', 'array:'.implode(',', ChannelPreferences::CHANNELS)],
            'events.*.channels.*' => ['boolean:strict'],
        ]);
        $user = $this->user($request);

        $changes = [];
        foreach ((array) $request->input('events') as $entry) {
            foreach ((array) $entry['channels'] as $channel => $enabled) {
                $changes[(string) $entry['event']][(string) $channel] = (bool) $enabled;
            }
        }
        $this->preferences->update($user, $changes);

        return ApiResponse::ok(['events' => $this->events($user)]);
    }

    /** @return list<array{event: string, channels: array<string, bool>}> */
    private function events(User $user): array
    {
        $events = [];
        foreach ($this->preferences->matrix($user) as $event => $channels) {
            $events[] = ['event' => $event, 'channels' => $channels];
        }

        return $events;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
