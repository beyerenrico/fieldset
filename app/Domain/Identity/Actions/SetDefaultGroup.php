<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;

final readonly class SetDefaultGroup
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function handle(Membership $membership, ?int $groupId, ?User $actor = null): void
    {
        if ($groupId !== null) {
            // Only a group of the membership's organization is accepted.
            $groupId = $this->tenant->run($membership->organization_id, fn (): ?int => Group::query()->whereKey($groupId)->value('id'));
        }

        if ($membership->default_group_id === $groupId) {
            return;
        }

        $before = $membership->default_group_id;
        $membership->forceFill(['default_group_id' => $groupId])->save();
        $this->audit->record('member.default_group_changed', $membership, ['before' => $before, 'after' => $groupId], $actor);
    }
}
