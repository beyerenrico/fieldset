<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The organization half of the R64 carry (plan U17, KTD18, ADR 0085 amendment after review), from the pre-restore
 * database (`source`) onto the restored one (`target`): what an administrator took away after the backup stays away.
 *
 * - SSO: external identities unlinked, identity providers, their claim mappings and SSO domains deleted → deleted;
 *   a provider's status, JIT and enforcement as the source has them; a domain unverified in the source → unverified;
 * - group memberships (`group_user`) of accounts both databases know, and form access grants (`form_access`), removed
 *   in the source → removed;
 * - organizations soft-deleted in the source → soft-deleted;
 * - webhook endpoints deleted in the source → deleted, deactivated → deactivated.
 *
 * Query-builder writes only (no model events, no audit entries); nothing created after the backup is added.
 */
final readonly class ScopeCarry
{
    private const array PROVIDER = ['status', 'jit_enabled', 'enforce_sso'];

    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target) {}

    /** @return array<string, int> */
    public function carry(): array
    {
        return [
            'external_identities_deleted' => $this->missing('external_identities'),
            'identity_provider_mappings_deleted' => $this->missing('identity_provider_mappings'),
            'identity_providers_deleted' => $this->missing('identity_providers'),
            'identity_providers_updated' => $this->providers(),
            'sso_domains_deleted' => $this->missing('sso_domains'),
            'sso_domains_unverified' => $this->unverified(),
            'group_memberships_removed' => $this->groupMemberships(),
            'form_access_removed' => $this->formAccess(),
            'organizations_deleted' => $this->organizations(),
            'webhook_endpoints_deleted' => $this->missing('webhook_endpoints'),
            'webhook_endpoints_deactivated' => $this->webhooks(),
        ];
    }

    /** Deletes target rows (by id) the source no longer has. */
    private function missing(string $table): int
    {
        $source = array_flip(array_map(strval(...), $this->source->table($table)->pluck('id')->all()));
        $gone = array_values(array_filter($this->target->table($table)->pluck('id')->all(), static fn (mixed $id): bool => ! isset($source[(string) $id])));
        $count = 0;
        foreach (array_chunk($gone, 1000) as $chunk) {
            $count += $this->target->table($table)->whereIn('id', $chunk)->delete();
        }

        return $count;
    }

    private function providers(): int
    {
        $source = $this->source->table('identity_providers')->get(['id', ...self::PROVIDER])->keyBy('id');
        $count = 0;
        foreach ($this->target->table('identity_providers')->get(['id', ...self::PROVIDER]) as $provider) {
            $before = $source->get($provider->id);
            $values = $before instanceof stdClass ? array_intersect_key((array) $before, array_flip(self::PROVIDER)) : null;
            if ($values !== null && $values !== array_intersect_key((array) $provider, array_flip(self::PROVIDER))) {
                $count += $this->target->table('identity_providers')->where('id', $provider->id)->update($values);
            }
        }

        return $count;
    }

    private function unverified(): int
    {
        $unverified = $this->source->table('sso_domains')->whereNull('verified_at')->pluck('id')->all();

        return $unverified === [] ? 0 : $this->target->table('sso_domains')->whereIn('id', $unverified)->whereNotNull('verified_at')->update(['verified_at' => null]);
    }

    /** Group memberships the source dropped, for accounts both databases know (a missing account is deactivated). */
    private function groupMemberships(): int
    {
        $users = array_flip(array_map(strval(...), $this->source->table('users')->pluck('id')->all()));
        $kept = array_flip($this->source->table('group_user')->get(['group_id', 'user_id'])->map(static fn (stdClass $row): string => $row->group_id.'|'.$row->user_id)->all());
        $count = 0;
        foreach ($this->target->table('group_user')->get(['id', 'group_id', 'user_id']) as $row) {
            if (isset($users[(string) $row->user_id]) && ! isset($kept[$row->group_id.'|'.$row->user_id])) {
                $count += $this->target->table('group_user')->where('id', $row->id)->delete();
            }
        }

        return $count;
    }

    /** Form access grants (form, group or role) the source no longer has. */
    private function formAccess(): int
    {
        $key = static fn (stdClass $row): string => $row->form_id.'|'.($row->group_id ?? '').'|'.($row->role_name ?? '');
        $kept = array_flip($this->source->table('form_access')->get(['form_id', 'group_id', 'role_name'])->map($key)->all());
        $count = 0;
        foreach ($this->target->table('form_access')->get(['id', 'form_id', 'group_id', 'role_name']) as $row) {
            if (! isset($kept[$key($row)])) {
                $count += $this->target->table('form_access')->where('id', $row->id)->delete();
            }
        }

        return $count;
    }

    private function organizations(): int
    {
        $count = 0;
        foreach ($this->source->table('organizations')->whereNotNull('deleted_at')->get(['id', 'deleted_at']) as $organization) {
            $count += $this->target->table('organizations')->where('id', $organization->id)->whereNull('deleted_at')->update(['deleted_at' => $organization->deleted_at]);
        }

        return $count;
    }

    private function webhooks(): int
    {
        $inactive = $this->source->table('webhook_endpoints')->where('active', false)->pluck('id')->all();

        return $inactive === [] ? 0 : $this->target->table('webhook_endpoints')->whereIn('id', $inactive)->where('active', true)->update(['active' => false]);
    }
}
