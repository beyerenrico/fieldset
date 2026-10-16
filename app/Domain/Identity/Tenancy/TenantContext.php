<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tenancy;

use App\Domain\Identity\Models\Organization;
use Closure;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;

/**
 * The current organization (tenant). State lives in Laravel's hidden Context so it travels with
 * queued jobs and is hydrated before job models are unserialized (BUILD_PROMPT §6.2).
 * Bound as scoped(): the bypass flag never leaks between requests or jobs.
 */
final class TenantContext
{
    public const string CONTEXT_KEY = 'organization_id';

    private int $bypassDepth = 0;

    public function __construct(private readonly PermissionRegistrar $permissions) {}

    public function set(Organization|int $organization): void
    {
        $id = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        Context::addHidden(self::CONTEXT_KEY, $id);
        $this->permissions->setPermissionsTeamId($id);
    }

    public function clear(): void
    {
        Context::forgetHidden(self::CONTEXT_KEY);
        $this->permissions->setPermissionsTeamId(null);
    }

    public function id(): ?int
    {
        $id = Context::getHidden(self::CONTEXT_KEY);

        return is_int($id) ? $id : null;
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function require(): int
    {
        return $this->id() ?? throw TenantNotSetException::make();
    }

    public function organization(): ?Organization
    {
        $id = $this->id();

        return $id === null ? null : Organization::query()->find($id);
    }

    /**
     * Run a callback as the given organization, restoring the previous tenant afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Organization|int $organization, Closure $callback): mixed
    {
        $previous = $this->id();
        $this->set($organization);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->clear() : $this->set($previous);
        }
    }

    /**
     * Explicit opt-out for platform-level code (installers, operators, cross-tenant jobs).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutTenant(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /** Re-sync the spatie team id with the Context (after Context hydration in a worker). */
    public function syncPermissionTeam(): void
    {
        $this->permissions->setPermissionsTeamId($this->id());
    }
}
