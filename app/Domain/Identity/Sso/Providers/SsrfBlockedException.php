<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use RuntimeException;

/**
 * An outbound IdP request was refused by the SSRF guard (§11): not https, credentials in the URL, or a host
 * that resolves to a private, loopback, link-local or reserved address. The message is matched by the
 * FailureClassifier (packages wrap exceptions into their own messages).
 */
final class SsrfBlockedException extends RuntimeException
{
    public const string MARKER = 'blocked outbound request';

    public static function for(string $host, string $why): self
    {
        return new self(sprintf('SSO: %s to "%s" (%s).', self::MARKER, $host, $why));
    }
}
