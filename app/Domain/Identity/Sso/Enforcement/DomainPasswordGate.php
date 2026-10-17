<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enforcement;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;

/** Skeleton stub (M7, owner A): allows every password login and reset until implemented. */
final readonly class DomainPasswordGate implements PasswordAuthGate
{
    public function assertPasswordLogin(string $email): void {}

    public function assertPasswordReset(string $email): void {}

    public function isBreakGlass(User $user): bool
    {
        return false;
    }
}
