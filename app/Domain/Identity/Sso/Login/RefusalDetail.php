<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use Illuminate\Support\Facades\Context;

/**
 * A fixed code that explains an SsoLoginRefused beyond its client-facing reason (e.g. `domain_moved`). The
 * refusal is thrown inside CompleteSsoLogin's transaction, which rolls back any audit written there, so the
 * code travels in the request context to the auth.sso_refused entry FinishSsoLogin writes afterwards.
 */
final class RefusalDetail
{
    private const string KEY = 'sso.refusal_detail';

    public static function set(string $code): void
    {
        Context::addHidden(self::KEY, $code);
    }

    public static function pull(): ?string
    {
        $code = Context::getHidden(self::KEY);
        Context::forgetHidden(self::KEY);

        return is_string($code) ? $code : null;
    }
}
