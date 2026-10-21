<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use RuntimeException;

/** `fieldset config set operator-email` cannot grant this address; `reason` is the stable error code of the JSON. */
final class OperatorEmailRefused extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message, public readonly ?string $hint = null)
    {
        parent::__construct($message);
    }

    public static function ssoRequired(): self
    {
        return new self('sso_required', 'The domain of this address enforces single sign-on, and the operator panel accepts only password sign-ins.', 'Use an address whose domain does not enforce single sign-on.');
    }

    public static function inactive(): self
    {
        return new self('inactive', 'This account is deactivated.', 'Reactivate the account first, or use another address.');
    }
}
