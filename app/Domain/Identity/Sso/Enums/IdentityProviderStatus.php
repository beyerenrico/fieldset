<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enums;

/** draft until a successful test login, then active; error after failed health checks; disabled by an admin. */
enum IdentityProviderStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Error = 'error';
    case Disabled = 'disabled';
}
