<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Chain\AuditChain;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Append-only audit entry (BUILD_PROMPT §8.11, ADR 0038). Updates and deletes through the model are
 * refused, and a database trigger refuses them for every other path too: retention pruning deletes through
 * audit_prune(), sealing fills the hash chain columns once. Each entry is sealed into its organization's
 * hash chain after the writing transaction commits.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $actor_type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property int|null $chain_seq
 * @property string|null $prev_hash
 * @property string|null $hash
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Audit entries are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Audit entries are append-only.'));
        static::created(static function (self $activity): void {
            $id = (int) $activity->getKey();
            DB::connection($activity->getConnectionName())->afterCommit(static fn () => app(AuditChain::class)->sealQuietly($id));
        });
    }
}
