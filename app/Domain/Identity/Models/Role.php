<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\SystemRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Organization-scoped role (spatie teams mode, team = organization). Audited explicitly by the
 * role actions (one entry per change), not by model events. Not tenant-scoped by the
 * OrganizationScope: spatie's global permission cache must load roles of all organizations.
 *
 * @property int $id
 * @property string $name
 * @property int|null $organization_id
 */
class Role extends SpatieRole
{
    /**
     * Filament tenant ownership relationship.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isSystemRole(): bool
    {
        $name = $this->getAttributes()['name'] ?? null;

        return is_string($name) && SystemRole::tryFrom($name) !== null;
    }
}
