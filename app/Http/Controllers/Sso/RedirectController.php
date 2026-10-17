<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

/**
 * GET /auth/sso/{provider}/redirect: starts an OIDC or SAML login (client enum, optional login_hint and test).
 * Skeleton (M7, owner A): 501 until implemented.
 */
final class RedirectController
{
    public function __invoke(): never
    {
        abort(501);
    }
}
