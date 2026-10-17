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
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates the organization's identity provider (one per organization; ADR 0036).
 * - Settings are validated per type and flavor (ProviderConfigValidator); `$config` carries both the
 *   encrypted config keys and the column settings (ProviderSettings).
 * - The client secret is write-only: null keeps the stored one; SAML stores none.
 * - Changing the connection (type, flavor, issuer, tenant, client id, SAML metadata) needs a new test
 *   login: the status returns to draft. While SSO is enforced such a change is refused (it would lock
 *   everybody but the break-glass Owner out); rotating the secret is always allowed.
 * - Audited once as sso.provider_created / sso.provider_updated with the changed key names only, never
 *   values (the model's own entry is suppressed).
 */
final readonly class SaveIdentityProvider
{
    public function __construct(
        private ProviderConfigValidator $validator,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    public function handle(Organization $org, IdentityProviderType $type, string $name, array $config, ?string $newSecret, User $actor): IdentityProvider
    {
        return $this->tenant->run($org, fn (): IdentityProvider => DB::transaction(function () use ($org, $type, $name, $config, $newSecret, $actor): IdentityProvider {
            $provider = IdentityProvider::query()->lockForUpdate()->first() ?? new IdentityProvider;
            $valid = $this->validator->validate($org, $type, $name, $config, $newSecret, $provider->exists ? $provider : null);
            $before = $provider->exists ? $this->fingerprint($provider) : [];

            $provider->fill(['type' => $type, 'name' => trim($name), 'config' => $valid['config'], ...$valid['columns']]);
            $provider->client_secret = match (true) {
                $type === IdentityProviderType::Saml => null,
                filled($newSecret) => $newSecret,
                default => $provider->client_secret,
            };
            $this->resetStatusOnConnectionChange($provider, $before);

            $provider->disableLogging()->save();
            $provider->enableLogging();

            $changed = $this->changedKeys($before, $this->fingerprint($provider));
            if ($changed !== []) {
                $this->audit->record($before === [] ? 'sso.provider_created' : 'sso.provider_updated', $provider, ['changed' => $changed], $actor);
            }

            return $provider;
        }));
    }

    /** @param  array<string, string|null>  $before */
    private function resetStatusOnConnectionChange(IdentityProvider $provider, array $before): void
    {
        $after = $this->fingerprint($provider);
        $connection = ['type', ...array_map(static fn (string $key): string => 'config.'.$key, ProviderSettings::CONNECTION)];
        $changed = array_values(array_intersect($this->changedKeys($before, $after), $connection));
        if ($before === [] || $changed === []) {
            return;
        }

        if ($provider->enforce_sso) {
            throw ValidationException::withMessages([$this->errorKey($changed[0]) => __('sso_admin.provider.errors.connection_enforced')]);
        }
        if ($provider->status !== IdentityProviderStatus::Disabled) {
            $provider->forceFill(['status' => IdentityProviderStatus::Draft]);
        }
    }

    /**
     * Per-setting hashes: the audit compares settings without ever holding their values.
     *
     * @return array<string, string|null>
     */
    private function fingerprint(IdentityProvider $provider): array
    {
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
        foreach ([...ProviderSettings::OIDC_KEYS, ...ProviderSettings::SAML_KEYS] as $key) {
            $values['config.'.$key] = $hash($provider->config[$key] ?? null);
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
