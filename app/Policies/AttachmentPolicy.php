<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Attachment;

final class AttachmentPolicy extends TenantPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->owns($attachment) && $user->can('view', $attachment->submission);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return false;
    }
}
