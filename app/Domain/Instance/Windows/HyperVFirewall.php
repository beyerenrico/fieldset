<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/** `Enabled` of the Hyper-V firewall setting for WSL's VM creator (Get-NetFirewallHyperVVMSetting). */
enum HyperVFirewall: string
{
    case Enabled = 'True';
    case Disabled = 'False';
    case NotConfigured = 'NotConfigured';
    case Unknown = 'unknown';
}
