<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens;

use App\Domain\Identity\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The integration token behind an authenticated user (ADR 0043). Only a stored personal access token counts:
 * a Field App session carries Sanctum's TransientToken, and `Sanctum::actingAs()` (the session stand-in of
 * the test suite) an unsaved token double; both keep full session access.
 */
final class RequestToken
{
    public static function of(mixed $user): ?PersonalAccessToken
    {
        if (! $user instanceof User) {
            return null;
        }
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->exists ? $token : null;
    }

    /** The organization an integration token is bound to; null for sessions and unbound tokens. */
    public static function organizationId(mixed $user): ?int
    {
        $organizationId = self::of($user)?->getAttribute('organization_id');

        return $organizationId === null ? null : (int) $organizationId;
    }
}
