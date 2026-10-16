<?php

declare(strict_types=1);

namespace App\Domain\Reports\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Models\ReportExport;
use App\Policies\TenantPolicy;

/**
 * Exports follow the §8.6 "Reports and exports" column (`reports.view`); the rows inside follow
 * "View submissions" (ReportScope). A file is only ever downloaded by the user who requested it.
 */
final class ReportExportPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }

    public function view(User $user, ReportExport $export): bool
    {
        return $this->allowsFor($user, $export, Permission::ReportsView) && $export->user_id === $user->id;
    }

    public function download(User $user, ReportExport $export): bool
    {
        return $this->view($user, $export);
    }

    public function update(User $user, ReportExport $export): bool
    {
        return false;
    }

    public function delete(User $user, ReportExport $export): bool
    {
        return false;
    }
}
