<?php

declare(strict_types=1);

namespace App\Http\Middleware\Sso;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every Fortify route (config/fortify.php `middleware`): refuses password login and password reset
 * for emails whose domain enforces SSO (PasswordAuthGate, 422 `sso_required`). Skeleton (M7, owner A):
 * pass-through until implemented.
 */
final class BlockPasswordFlowsForSso
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
