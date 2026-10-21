<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Overview;

/**
 * One problem on the `/platform` instance page (R47, plan U20): a stable code (the `data-instance-problem` test hook),
 * how bad it is (`danger`, `warning` or `info`, a Filament colour), the translated text, and the `fieldset` command that acts
 * on it, when one does.
 */
final readonly class InstanceProblem
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public ?string $command = null,
    ) {}
}
