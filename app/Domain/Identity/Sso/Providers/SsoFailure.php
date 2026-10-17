<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Sso\Enums\SsoRefusal;

/**
 * A classified SSO failure (ADR 0036): the error code the client shows, a fixed cause code for the audit, and
 * — only when back-channel evidence shows the provider's configuration or availability at fault — the
 * misconfiguration reason (status=error, SsoMisconfigurationReporter). `detail` is a fixed text for anything
 * that came through the browser; only back-channel failures carry the (sanitized) exception message.
 */
final readonly class SsoFailure
{
    public function __construct(
        public SsoRefusal $code,
        public ?string $misconfiguration,
        public string $detail,
        public string $cause,
    ) {}

    public function isMisconfiguration(): bool
    {
        return $this->misconfiguration !== null;
    }
}
