<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/** A Windows TCP listener on an edge port (Get-NetTCPConnection -State Listen): address, PID and who it is. */
final readonly class WindowsListener
{
    public function __construct(public int $port, public string $address, public int $pid, public ListenerOwner $owner) {}
}
