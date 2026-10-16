<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;

/** Submissions follow the §8.6 "View all submissions" row; only the creator edits a draft. */
final class SubmissionPolicy extends TenantPolicy
{
    /** Lists (admin inbox) need any scope of the row; SubmissionVisibility narrows the rows. */
    public function viewAny(User $user): bool
    {
        foreach ([Permission::SubmissionsViewAll, Permission::SubmissionsViewOwnForms, Permission::SubmissionsViewAssigned, Permission::SubmissionsViewOwn] as $permission) {
            if ($this->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, Submission $submission): bool
    {
        return $this->owns($submission) && app(SubmissionVisibility::class)->canView($user, $submission);
    }

    public function update(User $user, Submission $submission): bool
    {
        return $this->owns($submission) && $submission->created_by === $user->id && $submission->status->isEditable();
    }

    public function delete(User $user, Submission $submission): bool
    {
        return false;
    }
}
