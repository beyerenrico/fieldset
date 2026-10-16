<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tenancy;

use LogicException;

/**
 * Thrown when tenant-owned data is touched without an organization in the TenantContext.
 * Platform-level code must opt out explicitly with TenantContext::withoutTenant().
 */
final class TenantNotSetException extends LogicException
{
    public static function make(): self
    {
        return new self('No organization is set in the tenant context. Set one, or opt out with withoutTenant().');
    }

    public static function crossTenantWrite(string $model): self
    {
        return new self("Refusing to write {$model} for an organization other than the current tenant.");
    }
}
