<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * For bookkeeping records that are never exposed directly (number sequences, idempotency keys,
 * submission events): nobody may view or change them through a UI or API.
 */
final class InternalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Model $record): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
