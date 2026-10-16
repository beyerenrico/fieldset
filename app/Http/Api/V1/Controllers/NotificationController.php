<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Inbox\NotificationInbox;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app notifications of the signed-in user (§8.9, §10): `GET /notifications`, `POST /notifications/read`.
 * Session only, no organization header: a user's notifications span all their organizations.
 */
final readonly class NotificationController
{
    public function __construct(private NotificationInbox $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $user = $this->user($request);
        $page = $this->inbox->paginate($user);

        return ApiResponse::ok(
            array_map(NotificationInbox::present(...), array_values(array_filter(
                $page->items(),
                static fn (mixed $item): bool => $item instanceof DatabaseNotification,
            ))),
            [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'total' => $page->total(), 'unread' => $this->inbox->unreadCount($user),
            ],
        );
    }

    /** Body `{ ids?: [uuid] }`; omitted (or null) marks all as read. */
    public function read(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['required', 'string', 'uuid'],
        ]);
        $user = $this->user($request);
        $ids = $request->input('ids');

        $this->inbox->markRead($user, is_array($ids) ? array_values(array_map(strval(...), $ids)) : null);

        return ApiResponse::ok(['unread' => $this->inbox->unreadCount($user)]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
