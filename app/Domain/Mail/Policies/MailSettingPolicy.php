<?php

declare(strict_types=1);

namespace App\Domain\Mail\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Models\MailSetting;
use App\Policies\TenantPolicy;

/** Mail settings belong to the organization settings (organization.manage, ADR 0017). */
final class MailSettingPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function view(User $user, MailSetting $setting): bool
    {
        return $this->allowsFor($user, $setting, Permission::OrganizationManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function update(User $user, MailSetting $setting): bool
    {
        return $this->allowsFor($user, $setting, Permission::OrganizationManage);
    }

    public function delete(User $user, MailSetting $setting): bool
    {
        return false;
    }
}
