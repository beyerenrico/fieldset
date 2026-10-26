<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/** A private IPv4 address of a connected, non-virtual Windows adapter with a gateway, and its network's profile. */
final readonly class WindowsAddress
{
    public function __construct(public string $address, public NetworkProfile $profile) {}
}
