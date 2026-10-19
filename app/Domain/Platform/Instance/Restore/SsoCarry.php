<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\SsoDomain;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * The SSO part of the R64 carry (plan U17, ADR 0085): external identities unlinked, identity providers, their claim
 * mappings and SSO domains deleted in the source → deleted; a provider's status, JIT and enforcement as the source has
 * them; a domain unverified in the source → unverified.
 *
 * Cascade guard: an identity or mapping whose provider (or role, group, account, organization) the source no longer has
 * is left to the provider's own carried deletion (the foreign keys cascade). Mode audit (CarryScope): a provider needs
 * `sso.provider_deleted`/`deleted`, a provider change `sso.provider_updated`, `sso.provider_status_changed`,
 * `sso.enforcement_changed` or `updated`, a mapping its own `deleted` or its provider's `sso.provider_updated`, an
 * identity its provider's `sso.connection_changed`, a domain `sso.domain_removed`/`deleted` or `sso.domain_unverified`.
 */
final readonly class SsoCarry
{
    private const array PROVIDER = ['status', 'jit_enabled', 'enforce_sso'];

    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target, private CarryScope $scope) {}

    /** @return array<string, int> */
    public function carry(): array
    {
        $connections = $this->scope->subjects(IdentityProvider::class, ['sso.connection_changed']);
        $updates = $this->scope->subjects(IdentityProvider::class, ['sso.provider_updated', 'sso.connection_changed']);
        $mappings = $this->scope->subjects(IdentityProviderMapping::class, ['deleted']);

        return [
            'external_identities_deleted' => $this->missing('external_identities', 'external_identities_deleted', ['identity_providers' => 'identity_provider_id', 'users' => 'user_id', 'organizations' => 'organization_id'],
                $connections === null ? null : static fn (stdClass $row): bool => isset($connections[(string) $row->identity_provider_id])),
            'identity_provider_mappings_deleted' => $this->missing('identity_provider_mappings', 'identity_provider_mappings_deleted', ['identity_providers' => 'identity_provider_id', 'roles' => 'role_id', 'groups' => 'group_id', 'organizations' => 'organization_id'],
                $mappings === null ? null : static fn (stdClass $row): bool => isset($mappings[(string) $row->id]) || isset($updates[(string) $row->identity_provider_id])),
            'identity_providers_deleted' => $this->missingBySubject('identity_providers', 'identity_providers_deleted', IdentityProvider::class, ['sso.provider_deleted', 'deleted']),
            'identity_providers_updated' => $this->providers(),
            'sso_domains_deleted' => $this->missingBySubject('sso_domains', 'sso_domains_deleted', SsoDomain::class, ['sso.domain_removed', 'deleted']),
            'sso_domains_unverified' => $this->unverified(),
        ];
    }

    /**
     * Deletes target rows the source no longer has, unless a parent is gone too (cascade) or no audit entry covers them.
     *
     * @param  array<string, string>  $parents  source table => column of the row
     * @param  (callable(stdClass): bool)|null  $covered  whether an audit entry after the backup covers the row (null: mode state)
     */
    private function missing(string $table, string $item, array $parents, ?callable $covered): int
    {
        $kept = array_flip(array_map(strval(...), $this->source->table($table)->pluck('id')->all()));
        $count = 0;
        foreach ($this->target->table($table)->get(['id', ...array_values($parents)]) as $row) {
            $audited = $covered === null ? null : ($covered($row) ? [(string) $row->id => true] : []);
            if (isset($kept[(string) $row->id]) || $this->scope->orphaned($item, array_map(static fn (string $column): mixed => $row->{$column}, $parents))
                || ! $this->scope->permits($item, $audited, $row->id)) {
                continue;
            }
            $count += $this->target->table($table)->where('id', $row->id)->delete();
        }

        return $count;
    }

    /**
     * Deletes target rows the source no longer has (organization still there), covered by an audit entry on the row.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $events
     */
    private function missingBySubject(string $table, string $item, string $model, array $events): int
    {
        $kept = array_flip(array_map(strval(...), $this->source->table($table)->pluck('id')->all()));
        $audited = $this->scope->subjects($model, $events);
        $count = 0;
        foreach ($this->target->table($table)->get(['id', 'organization_id']) as $row) {
            if (isset($kept[(string) $row->id]) || $this->scope->orphaned($item, ['organizations' => $row->organization_id])
                || ! $this->scope->permits($item, $audited, $row->id)) {
                continue;
            }
            $count += $this->target->table($table)->where('id', $row->id)->delete();
        }

        return $count;
    }

    private function providers(): int
    {
        $source = $this->source->table('identity_providers')->get(['id', ...self::PROVIDER])->keyBy('id');
        $audited = $this->scope->subjects(IdentityProvider::class, ['sso.provider_updated', 'sso.provider_status_changed', 'sso.enforcement_changed', 'updated']);
        $count = 0;
        foreach ($this->target->table('identity_providers')->get(['id', ...self::PROVIDER]) as $provider) {
            $before = $source->get($provider->id);
            $values = $before instanceof stdClass ? array_intersect_key((array) $before, array_flip(self::PROVIDER)) : null;
            if ($values !== null && $values !== array_intersect_key((array) $provider, array_flip(self::PROVIDER))
                && $this->scope->permits('identity_providers_updated', $audited, $provider->id)) {
                $count += $this->target->table('identity_providers')->where('id', $provider->id)->update($values);
            }
        }

        return $count;
    }

    private function unverified(): int
    {
        $unverified = $this->source->table('sso_domains')->whereNull('verified_at')->pluck('id')->all();
        $audited = $this->scope->subjects(SsoDomain::class, ['sso.domain_unverified']);
        $count = 0;
        foreach ($unverified === [] ? [] : $this->target->table('sso_domains')->whereIn('id', $unverified)->whereNotNull('verified_at')->pluck('id')->all() as $id) {
            if ($this->scope->permits('sso_domains_unverified', $audited, $id)) {
                $count += $this->target->table('sso_domains')->where('id', $id)->update(['verified_at' => null]);
            }
        }

        return $count;
    }
}
