<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\SystemRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Organization-scoped role (spatie teams mode, team = organization). Not tenant-scoped by the
 * OrganizationScope: spatie's global permission cache must load roles of all organizations.
 *
 * @property int $id
 * @property string $name
 * @property int|null $organization_id
 */
class Role extends SpatieRole
{
    use LogsActivity;

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
        return SystemRole::tryFrom($this->name) !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'organization_id'])->logOnlyDirty()->useLogName('audit');
    }
}
