<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Identity\Enums\MembershipStatus;
use Illuminate\Database\ConnectionInterface;
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
 * Query-builder writes only (no model events, no audit entries); nothing granted after the backup is added.
 */
final readonly class AccessCarry
{
    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target) {}

    /** @return array{devices_revoked: int, devices_wiped: int, memberships_deactivated: int, roles_removed: int, permissions_removed: int, tokens_deleted: int, operators_revoked: int} */
    public function carry(): array
    {
        return [
            ...$this->devices(),
            'memberships_deactivated' => $this->memberships(),
            'roles_removed' => $this->assignments('model_has_roles', 'role_id'),
            'permissions_removed' => $this->assignments('model_has_permissions', 'permission_id'),
            'tokens_deleted' => $this->missing('personal_access_tokens', 'id'),
            'operators_revoked' => $this->missing('platform_admins', 'user_id'),
        ];
    }

    /** @return array{devices_revoked: int, devices_wiped: int} */
    private function devices(): array
    {
        $source = $this->source->table('devices')->get(['user_id', 'device_uuid', 'revoked_at', 'wipe_requested_at'])
            ->keyBy(static fn (stdClass $device): string => $device->user_id.'|'.$device->device_uuid);
        $counts = ['devices_revoked' => 0, 'devices_wiped' => 0];
        foreach ($this->target->table('devices')->get(['id', 'user_id', 'device_uuid', 'revoked_at', 'wipe_requested_at']) as $device) {
            $before = $source->get($device->user_id.'|'.$device->device_uuid);
            $revoked = $before === null ? now() : $before->revoked_at;
            $wipe = $before === null ? now() : $before->wipe_requested_at;
            $changes = array_filter([
                'revoked_at' => $device->revoked_at === null ? $revoked : null,
                'wipe_requested_at' => $device->wipe_requested_at === null ? $wipe : null,
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
        $deactivated = MembershipStatus::Deactivated->value;
        $count = 0;
        foreach ($this->target->table('organization_user')->where('status', '!=', $deactivated)->get(['id', 'organization_id', 'user_id']) as $row) {
            $before = $source->get($row->organization_id.'|'.$row->user_id);
            if ($before === null || $before === $deactivated) {
                $this->target->table('organization_user')->where('id', $row->id)->update(['status' => $deactivated]);
                $count++;
            }
        }

        return $count;
    }

    /** Assignments (spatie/permission, team = organization) present in the target but not in the source. */
    private function assignments(string $table, string $column): int
    {
        $columns = [$column, 'model_type', 'model_id', 'organization_id'];
        $key = static fn (stdClass $row): string => implode('|', array_map(static fn (string $c): string => (string) $row->{$c}, $columns));
        $source = array_flip($this->source->table($table)->get($columns)->map($key)->all());
        $count = 0;
        foreach ($this->target->table($table)->get($columns) as $row) {
            if (isset($source[$key($row)])) {
                continue;
            }
            $query = $this->target->table($table)->where($column, $row->{$column})->where('model_type', $row->model_type)->where('model_id', $row->model_id);
            $count += ($row->organization_id === null ? $query->whereNull('organization_id') : $query->where('organization_id', $row->organization_id))->delete();
        }

        return $count;
    }

    /** Deletes target rows whose key the source no longer has. */
    private function missing(string $table, string $column): int
    {
        $source = array_flip(array_map(strval(...), $this->source->table($table)->pluck($column)->all()));
        $gone = array_values(array_filter($this->target->table($table)->pluck($column)->all(), static fn (mixed $value): bool => ! isset($source[(string) $value])));
        $count = 0;
        foreach (array_chunk($gone, 1000) as $chunk) { // far below Postgres' 65535 bind parameters
            $count += $this->target->table($table)->whereIn($column, $chunk)->delete();
        }

        return $count;
    }
}
