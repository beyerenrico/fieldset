<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

/**
 * POST /auth/sso/{provider}/saml/acs: runs without session, error sharing and CSRF (a cross-site POST carries
 * no Lax cookie); validates statelessly, stores a one-time ticket and answers 303 to sso.saml.complete.
 * Skeleton (M7, owner A): 501 until implemented.
 */
final class SamlAcsController
{
    public function __invoke(): never
    {
        abort(501);
    }
}
