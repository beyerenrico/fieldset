<?php

declare(strict_types=1);

namespace App\Domain\Reports\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ReportVisibility;
use App\Domain\Reports\Models\SavedReport;
use App\Policies\TenantPolicy;

/**
 * Saved reports (§8.4, §8.6 "Reports and exports"): `reports.view` holders see their own and the
 * organization-shared ones; the creator edits and deletes, Owner/Admin (`organization.manage`) also
 * manage shared ones. Results always follow the viewer's submission visibility (SavedReportQuery).
 */
final class SavedReportPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }

    public function view(User $user, SavedReport $report): bool
    {
        return $this->allowsFor($user, $report, Permission::ReportsView)
            && ($report->visibility === ReportVisibility::Organization || $report->created_by === $user->id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }

    public function update(User $user, SavedReport $report): bool
    {
        return $this->allowsFor($user, $report, Permission::ReportsView)
            && ($report->created_by === $user->id
                || ($report->visibility === ReportVisibility::Organization && $this->allows($user, Permission::OrganizationManage)));
    }

    public function delete(User $user, SavedReport $report): bool
    {
        return $this->update($user, $report);
    }
}
