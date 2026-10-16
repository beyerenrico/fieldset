<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Preferences;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;

/**
 * Per-user notification preferences per event and channel (BUILD_PROMPT §8.9). User-level: one
 * choice applies in every organization the user belongs to. Only explicit choices are stored.
 *
 * Defaults (no stored row):
 * - in-app (`database`): on for every event;
 * - email (`mail`): on for review_assigned, changes_requested, submission_approved,
 *   workflow_failed and export_ready (someone has to act or learns the outcome of their own work or
 *   request); off for form_available and workflow_notice (informational, frequent: in-app is enough).
 */
final class ChannelPreferences
{
    /** @var list<string> */
    public const array CHANNELS = [Notifier::CHANNEL_DATABASE, Notifier::CHANNEL_MAIL];

    /** @var list<NotificationEvent> events that email by default */
    private const array MAIL_BY_DEFAULT = [
        NotificationEvent::ReviewAssigned,
        NotificationEvent::ChangesRequested,
        NotificationEvent::SubmissionApproved,
        NotificationEvent::WorkflowFailed,
        NotificationEvent::ExportReady,
    ];

    public static function default(NotificationEvent $event, string $channel): bool
    {
        return match ($channel) {
            Notifier::CHANNEL_DATABASE => true,
            Notifier::CHANNEL_MAIL => in_array($event, self::MAIL_BY_DEFAULT, true),
            default => false,
        };
    }

    /**
     * Effective settings of one user for every event.
     *
     * @return array<string, array<string, bool>> event value => channel => enabled
     */
    public function matrix(User $user): array
    {
        $stored = $this->stored([$user->id]);
        $matrix = [];
        foreach (NotificationEvent::cases() as $event) {
            foreach (self::CHANNELS as $channel) {
                $matrix[$event->value][$channel] = $stored[$user->id][$event->value][$channel] ?? self::default($event, $channel);
            }
        }

        return $matrix;
    }

    /**
     * Stores explicit choices; events and channels not mentioned keep their current value.
     *
     * @param  array<string, array<string, bool>>  $changes  event value => channel => enabled
     */
    public function update(User $user, array $changes): void
    {
        $now = now();
        $rows = [];
        foreach ($changes as $event => $channels) {
            if (NotificationEvent::tryFrom($event) === null) {
                continue;
            }
            foreach ($channels as $channel => $enabled) {
                if (in_array($channel, self::CHANNELS, true)) {
                    $rows[] = [
                        'user_id' => $user->id, 'event' => $event, 'channel' => $channel,
                        'enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        if ($rows !== []) {
            NotificationPreference::query()->upsert($rows, ['user_id', 'event', 'channel'], ['enabled', 'updated_at']);
        }
    }

    /**
     * Enabled channels of many users for one event (one query).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>> user id => enabled channels
     */
    public function enabledChannels(array $userIds, NotificationEvent $event): array
    {
        $stored = $this->stored($userIds, $event);
        $enabled = [];
        foreach ($userIds as $id) {
            $enabled[$id] = array_values(array_filter(
                self::CHANNELS,
                fn (string $channel): bool => $stored[$id][$event->value][$channel] ?? self::default($event, $channel),
            ));
        }

        return $enabled;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, array<string, bool>>> user id => event => channel => enabled
     */
    private function stored(array $userIds, ?NotificationEvent $event = null): array
    {
        $stored = [];
        NotificationPreference::query()
            ->whereIn('user_id', $userIds)
            ->when($event instanceof NotificationEvent, fn ($q) => $q->where('event', $event?->value))
            ->get(['user_id', 'event', 'channel', 'enabled'])
            ->each(function (NotificationPreference $row) use (&$stored): void {
                $stored[$row->user_id][$row->event][$row->channel] = $row->enabled;
            });

        return $stored;
    }
}
