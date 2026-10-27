<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * WSL's idle timeouts from the Windows user's %UserProfile%\.wslconfig, in milliseconds (2.1.1 U7, R31): `[general]
 * instanceIdleTimeout` (a distro without a terminal stops after it; -1 never) and `[wsl2] vmIdleTimeout` (the VM stops
 * after every distro did). Null: the key is not set, so WSL's default applies.
 */
final readonly class WslIdleTimeouts
{
    public function __construct(public ?int $instance, public ?int $vm) {}

    /** Whether a distro without a terminal keeps running (instanceIdleTimeout=-1). */
    public function keepsDistro(): bool
    {
        return $this->instance === -1;
    }
}
