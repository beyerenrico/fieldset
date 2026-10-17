<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use Illuminate\Http\JsonResponse;

/**
 * POST /auth/sso/discover: `{mode: password}` or `{mode: sso, redirect_url, organization, enforced}` as plain
 * JSON (not the API envelope). Skeleton (M7, owner A): always password mode.
 */
final class DiscoverController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['mode' => 'password']);
    }
}
