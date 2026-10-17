<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Domain\Identity\Models\User;

/**
 * How "Delete my account" is confirmed (ADR 0039): with the current password, except for accounts whose
 * password nobody knows (`users.password_known` = false: created by SSO, or invited and joined through SSO),
 * which confirm by typing their email address. Setting a password later brings the password confirmation back.
 */
final class SelfErasureConfirmation
{
    public function byEmail(User $user): bool
    {
        return ! $user->password_known;
    }
}
