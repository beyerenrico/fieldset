<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;

/** A rendition is visible to whoever may see its submission; renditions never change (§8.5). */
final class RenditionPolicy extends TenantPolicy
{
    public function view(User $user, Rendition $rendition): bool
    {
        return $this->owns($rendition) && $user->can('view', $rendition->submission);
    }

    /** Re-render (a new rendition): reviewers and form managers who can see the submission. */
    public function rerender(User $user, Submission $submission): bool
    {
        return $user->can('view', $submission)
            && ($this->allows($user, Permission::FormsManage) || $this->allows($user, Permission::SubmissionsReview));
    }

    public function update(User $user, Rendition $rendition): bool
    {
        return false;
    }

    public function delete(User $user, Rendition $rendition): bool
    {
        return false;
    }
}
