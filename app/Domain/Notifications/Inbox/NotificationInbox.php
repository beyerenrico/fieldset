<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Inbox;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Delivery\StoredNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A user's in-app notifications for the API (§8.9, §10): only their own and only those sent by the
 * Notifier (type app.notification), across all their organizations; each item carries its
 * organization_id. Other Filament database notifications (e.g. PDF import results) stay admin-only.
 */
final class NotificationInbox
{
    public const int PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, DatabaseNotification> newest first */
    public function paginate(User $user): LengthAwarePaginator
    {
        return $this->query($user)->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE);
    }

    public function unreadCount(User $user): int
    {
        return $this->query($user)->whereNull('read_at')->count();
    }

    /** @param  list<string>|null  $ids  null marks all as read; ids of other users are ignored */
    public function markRead(User $user, ?array $ids): void
    {
        $this->query($user)
            ->whereNull('read_at')
            ->when($ids !== null, fn (Builder $query) => $query->whereKey($ids ?? []))
            ->update(['read_at' => now()]);
    }

    /**
     * @return array{id: string, event: string|null, title: string, body: string|null, url: string|null,
     *               organization_id: int|null, read_at: string|null, created_at: string}
     */
    public static function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $text = is_array($data['text'] ?? null) ? $data['text'] : [];
        $body = is_string($text['body'] ?? null) ? $text['body'] : '';

        return [
            'id' => (string) $notification->id,
            'event' => is_string($data['event'] ?? null) ? $data['event'] : null,
            'title' => is_string($text['title'] ?? null) ? $text['title'] : '',
            'body' => $body === '' ? null : $body,
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'organization_id' => is_int($data['organization_id'] ?? null) ? $data['organization_id'] : null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String() ?? '',
        ];
    }

    /** @return Builder<DatabaseNotification> */
    private function query(User $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('type', StoredNotification::TYPE);
    }
}
