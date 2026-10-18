<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

/** The landing page's states (R27), each with DE/EN texts in lang/{de,en}/login_link.php. */
enum LoginLinkState: string
{
    case Valid = 'valid';
    case Used = 'used';
    case Expired = 'expired';
    case RateLimited = 'rate_limited';
    case Invalid = 'invalid';
    case SsoRequired = 'sso_required';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Valid => 200,
            self::Used, self::Expired => 410,
            self::RateLimited => 429,
            self::Invalid => 404,
            self::SsoRequired => 403,
        };
    }
}
