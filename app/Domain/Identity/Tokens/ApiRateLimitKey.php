<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Key of the `api` rate limiter (BUILD_PROMPT §10 "per user and per token", ADR 0043): each integration token
 * has its own bucket, so a busy integration cannot starve its user's Field App session or other tokens.
 */
final class ApiRateLimitKey
{
    public static function for(Request $request): string
    {
        // The sanctum guard (session first, then bearer token), independent of the middleware order.
        $user = $request->user('sanctum');
        $token = RequestToken::of($user);

        return match (true) {
            $token instanceof PersonalAccessToken => 'token:'.$token->getKey(),
            $user !== null => 'user:'.$user->getAuthIdentifier(),
            default => 'ip:'.$request->ip(),
        };
    }
}
