<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/** `DefaultInboundAction` of the Hyper-V firewall setting for WSL's VM creator. */
enum InboundAction: string
{
    case Allow = 'Allow';
    case Block = 'Block';
    case NotConfigured = 'NotConfigured';
    case Unknown = 'unknown';
}
