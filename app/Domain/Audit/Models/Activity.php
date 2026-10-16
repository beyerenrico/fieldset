<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Append-only audit entry. Updates and deletes through the model are refused; retention pruning
 * deletes through the query builder (BUILD_PROMPT §8.11).
 *
 * @property int|null $organization_id
 * @property string $actor_type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Audit entries are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Audit entries are append-only.'));
    }
}
