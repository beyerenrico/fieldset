<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Carbon\CarbonImmutable;

/**
 * The operation gate (R62, KTD19): while it is raised, external requests get 503 and only a request carrying the
 * operation's smoke token from the internal network passes (OperationGate). The token is random per operation and
 * lives only in runtime.json, which `fsa` writes with raiseGate() and clears with lowerGate().
 */
final readonly class GateState
{
    public function __construct(
        public bool $raised,
        public ?string $operation = null,
        public ?CarbonImmutable $since = null,
        public ?string $smokeToken = null,
    ) {}

    /** The configured runtime file cannot be used and the process has no last good value (KTD11: fail closed). */
    public static function failClosed(): self
    {
        return new self(true);
    }
}
