<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Notifications\Delivery\StoredNotification;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;

/** Shared fixtures for the notification tests. */
final class NotificationFixtures
{
    /** @param  array<string, string|int|float|null>  $params */
    public static function message(
        NotificationEvent $event,
        ?Organization $organization,
        array $params = [],
        ?string $url = '/app/forms',
        ?string $submissionUuid = null,
    ): NotificationMessage {
        return new NotificationMessage(
            $event,
            'notifications.messages.form_available.title',
            'notifications.messages.form_available.body',
            ['form' => 'Service report', 'version' => 1, ...$params],
            $url,
            $organization?->id,
            $submissionUuid,
        );
    }

    /** @return Collection<int, DatabaseNotification> the user's Notifier notifications, newest first */
    public static function stored(User $user): Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->where('type', StoredNotification::TYPE)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, MailMessage> every logged mail, across organizations */
    public static function mails(): Collection
    {
        return app(TenantContext::class)->withoutTenant(fn () => MailMessage::query()->orderBy('id')->get());
    }

    /** @return list<string> */
    public static function mailRecipients(): array
    {
        return array_values(self::mails()->pluck('recipient_email')->all());
    }
}
