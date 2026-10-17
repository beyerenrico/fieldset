<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Sso\Enums\SsoRefusal;

/**
 * A classified SSO failure: the error code the client shows, and — when the provider's configuration or
 * availability is at fault — the misconfiguration reason (status=error, SsoMisconfigurationReporter).
 */
final readonly class SsoFailure
{
    public function __construct(
        public SsoRefusal $code,
        public ?string $misconfiguration,
        public string $detail,
    ) {}

    public function isMisconfiguration(): bool
    {
        return $this->misconfiguration !== null;
    }
}
