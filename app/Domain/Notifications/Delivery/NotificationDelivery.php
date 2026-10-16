<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Delivery;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one message to a batch of users (§8.9), run by the queued DeliverNotification job inside
 * the message's organization. Per recipient: active user, still an active member of the organization,
 * channels = caller's channels ∩ the user's preferences for the event. Each channel of each recipient
 * is delivered in its own savepoint; a failure is reported and never stops the others.
 */
final readonly class NotificationDelivery
{
    public function __construct(
        private ChannelPreferences $preferences,
        private NotificationRenderer $renderer,
        private QueueMail $queueMail,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $channels
     */
    public function deliver(NotificationMessage $message, array $userIds, array $channels, ?int $organizationId): void
    {
        $organization = $organizationId === null ? null : Organization::query()->find($organizationId);
        if ($organizationId !== null && ! $organization instanceof Organization) {
            Log::warning('Notification skipped: organization not found', ['organization_id' => $organizationId, 'event' => $message->event->value]);

            return;
        }

        $users = $this->recipients($userIds, $organization);
        $enabled = $this->preferences->enabledChannels(array_values(array_map(intval(...), $users->modelKeys())), $message->event);

        foreach ($users as $user) {
            $userChannels = array_values(array_intersect($channels, $enabled[$user->id] ?? []));
            if ($userChannels !== []) {
                $this->deliverTo($user, $message, $userChannels, $organization);
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function recipients(array $userIds, ?Organization $organization): Collection
    {
        return User::query()
            ->whereKey($userIds)
            ->where('status', UserStatus::Active->value)
            ->when($organization instanceof Organization, fn ($query) => $query->whereHas(
                'memberships',
                fn ($membership) => $membership->where('organization_id', $organization?->id)->where('status', MembershipStatus::Active->value),
            ))
            ->orderBy('id')
            ->get();
    }

    /** @param  list<string>  $channels */
    private function deliverTo(User $user, NotificationMessage $message, array $channels, ?Organization $organization): void
    {
        $rendered = $this->renderer->render($message, $user, $organization);

        foreach ($channels as $channel) {
            $this->attempt($user, $message, $channel, match ($channel) {
                Notifier::CHANNEL_DATABASE => fn () => $user->notifyNow(
                    new StoredNotification(StoredNotification::payload($message, $rendered, $organization?->id)),
                ),
                Notifier::CHANNEL_MAIL => fn () => $this->mail($user, $message, $rendered, $organization),
                default => null,
            });
        }
    }

    private function mail(User $user, NotificationMessage $message, RenderedNotification $rendered, ?Organization $organization): void
    {
        if (! $organization instanceof Organization) {
            return; // organization mail needs a sender and branding; in-app only without one
        }

        $mail = new NotificationMail($organization->id, $rendered->title, $rendered->body, $rendered->absoluteUrl(), $rendered->locale, $message->actionKey);
        $this->queueMail->handle($mail, $user->email, $user->name, $message->submissionUuid);
    }

    private function attempt(User $user, NotificationMessage $message, string $channel, ?Closure $send): void
    {
        if (! $send instanceof Closure) {
            return;
        }

        try {
            DB::transaction(static function () use ($send): void { // savepoint: a failed insert never aborts the surrounding transaction
                $send();
            });
        } catch (Throwable $e) {
            Log::warning('Notification delivery failed', [
                'event' => $message->event->value, 'user_id' => $user->id, 'channel' => $channel,
                'error' => $e::class.': '.$e->getMessage(),
            ]);
        }
    }
}
