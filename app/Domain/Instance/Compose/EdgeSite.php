<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InvalidState;

/**
 * What the edge's Caddyfile is rendered from (ADR 0075): the domain, the ACME directory with its optional contact and
 * custom HTTPS root, and whether the catcher UI is served under /_mail/ (capture mail). The values come from the
 * state, which InputGrammar already validated; CaddyfileRenderer quotes and checks them again anyway.
 */
final readonly class EdgeSite
{
    public function __construct(
        public string $domain,
        public string $acmeCa = InstanceSettings::ACME_PRODUCTION,
        public ?string $acmeEmail = null,
        public bool $acmeCaRoot = false,
        public bool $catcher = true,
    ) {}

    public static function fromSettings(InstanceSettings $settings): self
    {
        return new self(
            domain: $settings->domain() ?? throw new InvalidState('Domain mode needs the setting [domain].'),
            acmeCa: $settings->acmeCa(),
            acmeEmail: $settings->acmeEmail(),
            acmeCaRoot: $settings->acmeCaRoot() !== null,
            catcher: $settings->runsCatcher(),
        );
    }
}
