<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/** WSL's networking mode (`networkingMode` in %USERPROFILE%\.wslconfig; NAT unless set). */
enum WslNetworking: string
{
    case Nat = 'nat';
    case Mirrored = 'mirrored';
    case Unknown = 'unknown';
}
