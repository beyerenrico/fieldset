<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Support\NotImplemented;
use Illuminate\Validation\ValidationException;

/** Skeleton stub (M7, owner B): switches enforcement with its preconditions (ADR 0036, Enforcement). */
final readonly class SetSsoEnforcement
{
    /** @throws ValidationException */
    public function handle(IdentityProvider $p, bool $on, ?User $breakGlass, User $actor): void
    {
        NotImplemented::raise(__METHOD__, $p, $on, $breakGlass, $actor);
    }
}
