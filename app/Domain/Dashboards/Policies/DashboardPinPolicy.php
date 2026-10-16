<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Policies;

use App\Domain\Dashboards\Models\DashboardPin;
use App\Domain\Identity\Models\User;
use App\Policies\TenantPolicy;

/**
 * Dashboard pins are personal (ADR 0034): a user sees and removes only their own pins in the current
 * organization. Pins are created by DashboardPins::pin(), which requires SavedReportPolicy::view.
 */
final class DashboardPinPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, DashboardPin $pin): bool
    {
        return $this->owns($pin) && $user->isActive() && $pin->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DashboardPin $pin): bool
    {
        return false;
    }

    public function delete(User $user, DashboardPin $pin): bool
    {
        return $this->view($user, $pin);
    }
}
