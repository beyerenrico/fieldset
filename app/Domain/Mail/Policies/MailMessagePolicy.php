<?php

declare(strict_types=1);

namespace App\Domain\Mail\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Models\MailMessage;
use App\Policies\TenantPolicy;

/**
 * The mail log is read-only. Holders of the organization-settings capability (ADR 0017) see it
 * and may retry failed messages.
 */
final class MailMessagePolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrganizationManage);
    }

    public function view(User $user, MailMessage $message): bool
    {
        return $this->allowsFor($user, $message, Permission::OrganizationManage);
    }

    public function retry(User $user, MailMessage $message): bool
    {
        return $message->isRetryable() && $this->allowsFor($user, $message, Permission::OrganizationManage);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MailMessage $message): bool
    {
        return false;
    }

    public function delete(User $user, MailMessage $message): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
