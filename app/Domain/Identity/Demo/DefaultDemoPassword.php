<?php

declare(strict_types=1);

namespace App\Domain\Identity\Demo;

/**
 * The documented demo password `password` (DemoSeeder). Public knowledge, so never a secret: only a loopback-only
 * local instance keeps it (R29, AE2); `fsa` reports it in `data`, never under `secrets` (ADR 0081).
 */
final readonly class DefaultDemoPassword implements DemoPasswordSource
{
    public const string PASSWORD = 'password';

    public function passwordFor(string $email): string
    {
        return self::PASSWORD;
    }
}
