<?php

declare(strict_types=1);

namespace App\Domain\Mail\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\Models\DocumentLink;

/**
 * Document links are bookkeeping records: they are only reachable through their public token
 * route, never through a UI or API listing.
 */
final class DocumentLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, DocumentLink $link): bool
    {
        return false;
    }

    public function update(User $user, DocumentLink $link): bool
    {
        return false;
    }

    public function delete(User $user, DocumentLink $link): bool
    {
        return false;
    }
}
