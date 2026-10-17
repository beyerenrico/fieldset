<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates the organization's identity provider (one per organization; ADR 0036).
 * - Settings are validated per type and flavor (ProviderConfigValidator); `$config` carries both the
 *   encrypted config keys and the column settings (ProviderSettings).
 * - The client secret is write-only: null keeps the stored one; SAML stores none.
 * - Owner-only settings (the connection, attribute names, trust in unverified email, the JIT default role;
 *   ProviderSettings::OWNER_ONLY) need an Owner-level actor (SsoOwnerGuard), so creating a provider does too.
 * - Changing the connection (type, flavor, issuer, tenant, client id, SAML metadata) needs a new test
 *   login: the status returns to draft, the provider's linked identities are deleted (old subjects must not
 *   sign in through a new IdP), it is audited as `sso.connection_changed` and the other Owners are alerted
 *   (SsoSecurityAlerts, also when a provider is set up). While SSO is enforced such a change is refused (it
 *   would lock everybody but the break-glass Owner out); rotating the secret is always allowed.
 * - `$uuid` (optional, new providers only) is the public id the admin page already showed for the callback
 *   URLs; an invalid or taken value is ignored.
 * - Audited once as sso.provider_created / sso.provider_updated with the changed key names only, never
 *   values (the model's own entry is suppressed).
 */
final readonly class SaveIdentityProvider
{
    public function __construct(
        private ProviderConfigValidator $validator,
        private SsoOwnerGuard $owners,
        private SsoSecurityAlerts $alerts,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    public function handle(Organization $org, IdentityProviderType $type, string $name, array $config, ?string $newSecret, User $actor, ?string $uuid = null): IdentityProvider
    {
        /** @var array{IdentityProvider, string|null} $result */
        $result = $this->tenant->run($org, fn (): array => DB::transaction(function () use ($org, $type, $name, $config, $newSecret, $actor, $uuid): array {
            $provider = IdentityProvider::query()->lockForUpdate()->first() ?? new IdentityProvider;
            $existing = $provider->exists;
            $valid = $this->validator->validate($org, $type, $name, $config, $newSecret, $existing ? $provider : null, $actor);
            $before = $existing ? $this->fingerprint($provider) : [];
            $baseline = $existing ? $this->fingerprint($provider, $this->validator->normalizedConfig($provider)) : [];

            $provider->fill(['type' => $type, 'name' => trim($name), 'config' => $valid['config'], ...$valid['columns']]);
            $provider->client_secret = match (true) {
                $type === IdentityProviderType::Saml => null,
                filled($newSecret) => $newSecret,
                default => $provider->client_secret,
            };
            if (! $existing && $this->isFreeUuid($uuid)) {
                $provider->forceFill(['uuid' => $uuid]);
            }

            $this->owners->assertMayChange($this->changedKeys($baseline, $this->fingerprint($provider)), $actor, $org);
            $connection = $this->resetStatusOnConnectionChange($provider, $baseline);

            $provider->disableLogging()->save();
            $provider->enableLogging();

            $changed = $this->changedKeys($before, $this->fingerprint($provider));
            if ($changed !== []) {
                $this->audit->record($existing ? 'sso.provider_updated' : 'sso.provider_created', $provider, ['changed' => $changed], $actor);
            }
            if ($connection !== []) {
                $removed = $provider->externalIdentities()->delete();
                $this->audit->record('sso.connection_changed', $provider, ['changed' => $connection, 'identities_removed' => $removed], $actor);
            }

            $alert = match (true) {
                ! $existing => SsoSecurityAlerts::CONNECTION_CREATED,
                $connection !== [] => SsoSecurityAlerts::CONNECTION_CHANGED,
                default => null,
            };

            return [$provider, $alert];
        }));

        [$provider, $alert] = $result;
        if ($alert !== null) {
            $this->alerts->send($alert, $provider, $actor);
        }

        return $provider;
    }

    /**
     * Sets a changed connection back to draft (or refuses it while enforced).
     *
     * @param  array<string, string|null>  $baseline  fingerprint of the stored settings, normalized
     * @return list<string> the changed connection keys (empty for a new provider)
     */
    private function resetStatusOnConnectionChange(IdentityProvider $provider, array $baseline): array
    {
        $connection = ['type', ...array_map(static fn (string $key): string => 'config.'.$key, ProviderSettings::CONNECTION)];
        $changed = array_values(array_intersect($this->changedKeys($baseline, $this->fingerprint($provider)), $connection));
        if ($baseline === [] || $changed === []) {
            return [];
        }

        if ($provider->enforce_sso) {
            throw ValidationException::withMessages([$this->errorKey($changed[0]) => __('sso_admin.provider.errors.connection_enforced')]);
        }
        if ($provider->status !== IdentityProviderStatus::Disabled) {
            $provider->forceFill(['status' => IdentityProviderStatus::Draft]);
        }

        return $changed;
    }

    private function isFreeUuid(?string $uuid): bool
    {
        return is_string($uuid) && Str::isUuid($uuid)
            && ! IdentityProvider::query()->withoutTenant()->where('uuid', $uuid)->exists();
    }

    /**
     * Per-setting hashes: the audit compares settings without ever holding their values. `$config` replaces
     * the provider's config (the normalized stored settings).
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, string|null>
     */
    private function fingerprint(IdentityProvider $provider, ?array $config = null): array
    {
        $config ??= $provider->config;
        $hash = static fn (mixed $value): ?string => $value === null ? null : hash('sha256', (string) json_encode($value));
        $values = [
            'type' => $hash($provider->type->value),
            'name' => $hash($provider->name),
            'status' => $hash($provider->status->value),
            'client_secret' => $hash($provider->client_secret),
        ];
        foreach (ProviderSettings::COLUMNS as $column) {
            $values[$column] = $hash($provider->getAttribute($column));
        }
        foreach ([...ProviderSettings::OIDC_KEYS, ...ProviderSettings::SAML_KEYS, ...ProviderSettings::RUNTIME_ONLY_KEYS] as $key) {
            $values['config.'.$key] = $hash($config[$key] ?? null);
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     * @return list<string>
     */
    private function changedKeys(array $before, array $after): array
    {
        if ($before === []) {
            return array_keys(array_filter($after, static fn (?string $hash): bool => $hash !== null));
        }

        return array_keys(array_filter($after, static fn (?string $hash, string $key): bool => $hash !== ($before[$key] ?? null), ARRAY_FILTER_USE_BOTH));
    }

    private function errorKey(string $changedKey): string
    {
        return str_starts_with($changedKey, 'config.') ? substr($changedKey, 7) : $changedKey;
    }
}
