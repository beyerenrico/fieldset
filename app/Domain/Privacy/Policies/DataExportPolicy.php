<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Enums\DataExportKind;
use App\Domain\Privacy\Models\DataExport;
use App\Policies\TenantPolicy;

/**
 * A data export is only ever downloaded by the user who requested it, in its organization, while they still
 * may request it: audit log exports need audit.view; a user data export of someone else needs members.manage
 * (Owner, Admin), one's own needs only the membership.
 */
final class DataExportPolicy extends TenantPolicy
{
    public function view(User $user, DataExport $export): bool
    {
        return $this->owns($export) && $export->requested_by === $user->id && $user->isActive() && $this->mayRequest($user, $export);
    }

    public function download(User $user, DataExport $export): bool
    {
        return $this->view($user, $export);
    }

    public function update(User $user, DataExport $export): bool
    {
        return false;
    }

    public function delete(User $user, DataExport $export): bool
    {
        return false;
    }

    private function mayRequest(User $user, DataExport $export): bool
    {
        return match ($export->kind) {
            DataExportKind::AuditLog => $this->allows($user, Permission::AuditView),
            DataExportKind::UserData => $export->subject_user_id === $user->id
                ? $user->isMemberOf($export->organization_id)
                : $this->allows($user, Permission::MembersManage),
        };
    }
}
