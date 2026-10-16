<?php

declare(strict_types=1);

namespace App\Filament\Admin\Livewire;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Inbox\NotificationInbox;
use Filament\Livewire\DatabaseNotifications as PanelDatabaseNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The admin bell (§8.9) with the inbox's membership rule (M4 review S2): notifications of organizations
 * the user is no longer an active member of are hidden, like in `GET /api/v1/notifications`. Marking as
 * read and clearing use the same query, so hidden notifications are never touched.
 */
class DatabaseNotifications extends PanelDatabaseNotifications
{
    /** @return Builder<Model>|Relation<Model, Model, mixed> */
    public function getNotificationsQuery(): Builder|Relation
    {
        $query = parent::getNotificationsQuery();
        $user = $this->getUser();

        return $user instanceof User ? $query->where(NotificationInbox::activeMemberships($user)) : $query;
    }
}
