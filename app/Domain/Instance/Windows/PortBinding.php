<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * Docker Desktop's port binding setting (`PortBindingBehavior` in %APPDATA%\Docker\settings-store.json): with
 * local-only binding, published ports listen on Windows' loopback only, so phones cannot reach the edge.
 */
enum PortBinding: string
{
    case Default = 'default-port-binding';
    case DefaultLocal = 'default-local-port-binding';
    case LocalOnly = 'local-only-port-binding';
    case Unknown = 'unknown';
}
