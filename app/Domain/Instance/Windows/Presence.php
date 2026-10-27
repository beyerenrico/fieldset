<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/** Whether Windows has something (a firewall rule for an edge port): never a silent pass when unknown (R30). */
enum Presence: string
{
    case Present = 'present';
    case Missing = 'missing';
    case Unknown = 'unknown';
}
