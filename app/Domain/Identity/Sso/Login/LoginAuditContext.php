<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use Illuminate\Support\Facades\Context;

/**
 * How the next Auth::login of this request happened (ADR 0036, Audit): set before signing in, read by the
 * auth.login audit listener, so each login gets exactly one entry with `method` and `provider`. Without it a
 * login counts as `password` (or `break_glass`, which the listener derives itself).
 */
final class LoginAuditContext
{
    private const string METHOD = 'auth.login.method';

    private const string DETAILS = 'auth.login.details';

    public static function sso(IdentityProvider $provider, ExternalProfile $profile): void
    {
        Context::addHidden(self::METHOD, 'sso');
        Context::addHidden(self::DETAILS, [
            'provider' => $provider->uuid,
            'protocol' => $provider->type->value,
            'amr' => ClaimValues::list($profile->claims['amr'] ?? []),
            'email_verified_by' => is_string($profile->claims['email_verified_by'] ?? null) ? $profile->claims['email_verified_by'] : null,
        ]);
    }

    public static function method(): ?string
    {
        $method = Context::getHidden(self::METHOD);

        return is_string($method) ? $method : null;
    }

    /** @return array<string, mixed> */
    public static function details(): array
    {
        $details = Context::getHidden(self::DETAILS);

        return is_array($details) ? $details : [];
    }

    public static function clear(): void
    {
        Context::forgetHidden(self::METHOD);
        Context::forgetHidden(self::DETAILS);
    }
}
