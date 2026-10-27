<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/** The Windows network profile (NetworkCategory) of the network an address is on; Public blocks inbound traffic. */
enum NetworkProfile: string
{
    case Public = 'Public';
    case Private = 'Private';
    case DomainAuthenticated = 'DomainAuthenticated';
    case Unknown = 'unknown';
}
