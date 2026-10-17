<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enums;

/** Where an SSO login returns to; never a URL taken from the request (ADR 0036). */
enum SsoClient: string
{
    /** The Filament admin panel. */
    case Admin = 'admin';
    /** The Field App: /app/login?sso=ok or ?sso_error=<code>. */
    case App = 'app';
}
