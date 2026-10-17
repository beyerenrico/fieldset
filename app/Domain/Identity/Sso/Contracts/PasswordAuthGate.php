<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Contracts;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;

/**
 * Refuses password login and password reset before any password check when the email's domain is
 * verified by an organization that enforces SSO; the break-glass Owner is exempt (ADR 0036).
 */
interface PasswordAuthGate
{
    /** @throws SsoRequiredException */
    public function assertPasswordLogin(string $email): void;

    /** @throws SsoRequiredException */
    public function assertPasswordReset(string $email): void;

    public function isBreakGlass(User $user): bool;
}
