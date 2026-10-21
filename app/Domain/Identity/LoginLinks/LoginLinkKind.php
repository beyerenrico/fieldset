<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

/** The two one-time sign-in links (ADR 0056). */
enum LoginLinkKind: string
{
    /** The new owner's first link: a password-reset broker token, 60 minutes; it leads to setting a password. */
    case Owner = 'owner';

    /** `fieldset login-link` for an existing admin: 15 minutes; it ends in an ordinary /admin session. */
    case Admin = 'admin';
}
