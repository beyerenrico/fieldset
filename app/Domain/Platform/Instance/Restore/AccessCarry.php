<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Database\ConnectionInterface;
use Laravel\Sanctum\PersonalAccessToken;
use stdClass;

/**
 * The access half of the R64 carry (plan U17, KTD18, ADR 0085), from the pre-restore database (`source`) onto the
 * restored one (`target`):
 *
 * - devices revoked or asked to wipe → the same in the target; a device the source no longer has is revoked and wiped;
 * - memberships deactivated, or gone, in the source → deactivated;
 * - role and permission assignments the source no longer has → removed;
 * - API tokens deleted in the source → deleted;
 * - operator grants (`platform_admins`) revoked in the source → revoked.
 *
 * In mode audit (CarryScope) a revocation or wipe needs its timestamp after the backup, the other changes an audit entry
 * after it (`member.status_changed`, `member.roles_changed`, `api_token.revoked`, `platform.admin_revoked`); a row whose
 * organization or role is gone from the source is kept (cascade guard). Query-builder writes only; nothing granted after
 * the backup is added.
 */
final readonly class AccessCarry
{
    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target, private CarryScope $scope) {}

    /** @return array{devices_revoked: int, devices_wiped: int, memberships_deactivated: int, roles_removed: int, permissions_removed: int, tokens_deleted: int, operators_revoked: int} */
    public function carry(): array
    {
        return [
            ...$this->devices(),
            'memberships_deactivated' => $this->memberships(),
            'roles_removed' => $this->assignments('model_has_roles', 'role_id', 'roles', 'roles_removed', $this->memberPairs(['member.roles_changed'])),
            'permissions_removed' => $this->assignments('model_has_permissions', 'permission_id', 'permissions', 'permissions_removed', $this->scope->audited() ? [] : null),
            'tokens_deleted' => $this->tokens(),
            'operators_revoked' => $this->operators(),
        ];
    }

    /** @return array{devices_revoked: int, devices_wiped: int} */
    private function devices(): array
    {
        $source = $this->source->table('devices')->get(['user_id', 'device_uuid', 'revoked_at', 'wipe_requested_at'])
            ->keyBy(static fn (stdClass $device): string => $device->user_id.'|'.$device->device_uuid);
        $revocations = $this->scope->subjects(Device::class, ['device.revoked']);
        $counts = ['devices_revoked' => 0, 'devices_wiped' => 0];
        foreach ($this->target->table('devices')->get(['id', 'user_id', 'device_uuid', 'revoked_at', 'wipe_requested_at']) as $device) {
            $before = $source->get($device->user_id.'|'.$device->device_uuid);
            $changes = array_filter([
                'revoked_at' => $device->revoked_at === null && ($before === null || $before->revoked_at !== null) && $this->scope->permits('devices_revoked', $revocations, $before === null ? null : $device->id, $before?->revoked_at)
                    ? ($before === null ? now() : $before->revoked_at) : null,
                'wipe_requested_at' => $device->wipe_requested_at === null && ($before === null || $before->wipe_requested_at !== null) && $this->scope->permits('devices_wiped', null, null, $before?->wipe_requested_at)
                    ? ($before === null ? now() : $before->wipe_requested_at) : null,
            ], static fn (mixed $value): bool => $value !== null);
            if ($changes !== []) {
                $this->target->table('devices')->where('id', $device->id)->update($changes);
            }
            $counts['devices_revoked'] += array_key_exists('revoked_at', $changes) ? 1 : 0;
            $counts['devices_wiped'] += array_key_exists('wipe_requested_at', $changes) ? 1 : 0;
        }

        return $counts;
    }

    private function memberships(): int
    {
        $source = $this->source->table('organization_user')->get(['organization_id', 'user_id', 'status'])
            ->mapWithKeys(static fn (stdClass $row): array => [$row->organization_id.'|'.$row->user_id => $row->status]);
        $audited = $this->memberPairs(['member.status_changed']);
        $deactivated = MembershipStatus::Deactivated->value;
        $count = 0;
        foreach ($this->target->table('organization_user')->where('status', '!=', $deactivated)->get(['id', 'organization_id', 'user_id']) as $row) {
            $before = $source->get($row->organization_id.'|'.$row->user_id);
            if ($before !== null && $before !== $deactivated) {
                continue;
            }
            if ($this->scope->orphaned('memberships_deactivated', ['organizations' => $row->organization_id])
                || ! $this->scope->permits('memberships_deactivated', $audited, $row->organization_id.'|'.$row->user_id)) {
                continue;
            }
            $this->target->table('organization_user')->where('id', $row->id)->update(['status' => $deactivated]);
            $count++;
        }

        return $count;
    }

    /**
     * Assignments (spatie/permission, team = organization) present in the target but not in the source.
     *
     * @param  array<string, true>|null  $audited  `<organization>|<user>` pairs an audit entry names (null: mode state)
     */
    private function assignments(string $table, string $column, string $parent, string $item, ?array $audited): int
    {
        $columns = [$column, 'model_type', 'model_id', 'organization_id'];
        $key = static fn (stdClass $row): string => implode('|', array_map(static fn (string $c): string => (string) $row->{$c}, $columns));
        $source = array_flip($this->source->table($table)->get($columns)->map($key)->all());
        $count = 0;
        foreach ($this->target->table($table)->get($columns) as $row) {
            if (isset($source[$key($row)]) || $this->scope->orphaned($item, [$parent => $row->{$column}, 'organizations' => $row->organization_id])
                || ! $this->scope->permits($item, $audited, $row->organization_id.'|'.$row->model_id)) {
                continue;
            }
            $query = $this->target->table($table)->where($column, $row->{$column})->where('model_type', $row->model_type)->where('model_id', $row->model_id);
            $count += ($row->organization_id === null ? $query->whereNull('organization_id') : $query->where('organization_id', $row->organization_id))->delete();
        }

        return $count;
    }

    private function tokens(): int
    {
        $kept = array_flip(array_map(strval(...), $this->source->table('personal_access_tokens')->pluck('id')->all()));
        $audited = $this->scope->subjects(PersonalAccessToken::class, ['api_token.revoked']);
        $count = 0;
        foreach ($this->target->table('personal_access_tokens')->get(['id', 'organization_id']) as $token) {
            if (isset($kept[(string) $token->id]) || $this->scope->orphaned('tokens_deleted', ['organizations' => $token->organization_id])
                || ! $this->scope->permits('tokens_deleted', $audited, $token->id)) {
                continue;
            }
            $count += $this->target->table('personal_access_tokens')->where('id', $token->id)->delete();
        }

        return $count;
    }

    private function operators(): int
    {
        $kept = array_flip(array_map(strval(...), $this->source->table('platform_admins')->pluck('user_id')->all()));
        $audited = $this->scope->subjects(User::class, ['platform.admin_revoked']);
        $count = 0;
        foreach ($this->target->table('platform_admins')->pluck('user_id')->all() as $user) {
            if (! isset($kept[(string) $user]) && $this->scope->permits('operators_revoked', $audited, $user)) {
                $count += $this->target->table('platform_admins')->where('user_id', $user)->delete();
            }
        }

        return $count;
    }

    /**
     * `<organization>|<user>` pairs of the memberships an audit entry after the backup names (null in mode state).
     *
     * @param  list<string>  $events
     * @return array<string, true>|null
     */
    private function memberPairs(array $events): ?array
    {
        $ids = $this->scope->subjects(Membership::class, $events);
        if ($ids === null || $ids === []) {
            return $ids;
        }

        return array_fill_keys($this->source->table('organization_user')->whereIn('id', array_keys($ids))->get(['organization_id', 'user_id'])
            ->map(static fn (stdClass $row): string => $row->organization_id.'|'.$row->user_id)->all(), true);
    }
}
