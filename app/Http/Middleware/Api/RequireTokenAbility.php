<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Domain\Identity\Tokens\RequestToken;
use App\Domain\Identity\Tokens\TokenAbility;
use App\Http\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The abilities an integration token needs for a route (ADR 0043): `RequireTokenAbility::using(...)` in
 * routes/api.php; without abilities (`sessionOnly()`) the route stays Field App only. All listed abilities are
 * required, and the wildcard `*` grants nothing. Field App sessions pass: their role permissions are checked
 * by the controllers and policies, as they are for tokens on top of this check.
 */
final class RequireTokenAbility
{
    public static function using(TokenAbility $ability, TokenAbility ...$more): string
    {
        $values = array_map(static fn (TokenAbility $a): string => $a->value, [$ability, ...$more]);

        return self::class.':'.implode(',', $values);
    }

    public static function sessionOnly(): string
    {
        return self::class;
    }

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = RequestToken::of($request->user());
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if ($abilities === []) {
            throw new ApiException('session_required', __('api_tokens.errors.session_required'), 403);
        }

        $granted = is_array($token->abilities) ? $token->abilities : [];
        $missing = array_values(array_diff($abilities, $granted));
        if ($missing !== []) {
            throw new ApiException('insufficient_ability', __('api_tokens.errors.insufficient_ability'), 403, [
                'required' => array_values($abilities),
                'missing' => $missing,
            ]);
        }

        return $next($request);
    }
}
