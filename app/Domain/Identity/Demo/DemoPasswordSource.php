<?php

declare(strict_types=1);

namespace App\Domain\Identity\Demo;

use Illuminate\Container\Attributes\Bind;

/**
 * Where the demo users' passwords come from (BUILD_PROMPT_V2 R29, ADR 0081). The default is the well-known
 * password `password` (development, tests, E2E and a loopback-only local instance); `app:demo-seed --passwords=random`
 * binds RandomDemoPasswords instead, whenever the instance is reachable beyond loopback.
 */
#[Bind(DefaultDemoPassword::class)]
interface DemoPasswordSource
{
    /** The password a newly created demo user gets; asked only for users the seeder creates. */
    public function passwordFor(string $email): string;
}
