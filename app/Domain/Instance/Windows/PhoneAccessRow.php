<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * The rows of the Windows phone-access hint table (2.1.1 KTD10), in precedence order: the first row that matches is the
 * only one the doctor prints.
 */
enum PhoneAccessRow: string
{
    case PublicProfile = 'public_profile';
    case DesktopLocalOnly = 'desktop_local_only';
    case FactsUnknown = 'facts_unknown';
    case HostRule = 'host_rule';
    case PortProxy = 'portproxy';
    case HyperVRule = 'hyperv_rule';
}
