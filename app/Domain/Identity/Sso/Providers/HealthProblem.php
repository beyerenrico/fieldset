<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use RuntimeException;

/** A failed provider health expectation (FetchProviderHealth) with its misconfiguration reason. */
final class HealthProblem extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
