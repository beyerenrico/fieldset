<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Validates and normalizes an identity provider's settings per type and flavor (ADR 0036):
 * - issuer and metadata URLs are https unless `sso.allow_insecure_idp` (dev/CI only);
 * - Entra needs a tenant GUID (multi-tenant `common`/`organizations` is refused) and gets its issuer derived;
 *   Google gets the Google issuer;
 * - an OIDC client secret is required while none is stored;
 * - SAML needs a metadata URL or pasted IdP metadata XML (no DTDs, an IDPSSODescriptor);
 * - the default role and group belong to the organization (SsoTargets).
 * Error keys are the input keys (`client_secret` for the secret).
 */
final readonly class ProviderConfigValidator
{
    private const string NO_CONTROL = 'not_regex:/[\x00-\x1F\x7F]/';

    private const string GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const string SCOPE = '/^[\x21\x23-\x5B\x5D-\x7E]{1,64}$/';

    private const string CLAIM = '/^[A-Za-z0-9_.:\/-]{1,120}$/';

    public function __construct(private SsoTargets $targets) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{config: array<string, mixed>, columns: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function validate(Organization $org, IdentityProviderType $type, string $name, array $input, ?string $newSecret, ?IdentityProvider $existing): array
    {
        $input['scopes'] = $this->scopeList($input['scopes'] ?? null);
        $data = [...$input, 'name' => $name, 'client_secret' => $newSecret];
        $needsSecret = $type === IdentityProviderType::Oidc && blank($newSecret)
            && ($existing?->type !== IdentityProviderType::Oidc || ! $existing->hasClientSecret());

        $rules = [
            'name' => ['required', 'string', 'max:120', self::NO_CONTROL],
            ...($type === IdentityProviderType::Oidc ? $this->oidcRules($needsSecret) : $this->samlRules()),
            ...$this->columnRules(),
        ];

        $valid = Validator::make($data, $rules, [], $this->attributeNames())
            ->after(fn (ValidatorInstance $v) => $this->targets->check($v, $org, $data))
            ->validate();

        return [
            'config' => $type === IdentityProviderType::Oidc ? $this->oidcConfig($valid) : $this->samlConfig($valid),
            'columns' => [
                'jit_enabled' => (bool) ($valid['jit_enabled'] ?? false),
                'force_reauth' => (bool) ($valid['force_reauth'] ?? false),
                'default_role_id' => isset($valid['default_role_id']) ? (int) $valid['default_role_id'] : null,
                'default_group_id' => isset($valid['default_group_id']) ? (int) $valid['default_group_id'] : null,
            ],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function oidcRules(bool $needsSecret): array
    {
        return [
            'flavor' => ['required', Rule::enum(OidcFlavor::class)],
            'issuer' => ['exclude_unless:flavor,generic', 'required', 'string', 'max:2048', 'url', $this->idpUrl(...)],
            'tenant' => ['exclude_unless:flavor,entra', 'required', 'string', 'regex:'.self::GUID],
            'client_id' => ['required', 'string', 'max:255', self::NO_CONTROL],
            'client_secret' => [$needsSecret ? 'required' : 'nullable', 'string', 'max:4096', self::NO_CONTROL],
            'scopes' => ['array', 'max:20'],
            'scopes.*' => ['string', 'regex:'.self::SCOPE],
            'groups_claim' => ['nullable', 'string', 'regex:'.self::CLAIM],
            'trust_unverified_email' => ['boolean'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function samlRules(): array
    {
        return [
            'metadata_url' => ['nullable', 'required_without:metadata_xml', 'string', 'max:2048', 'url', $this->idpUrl(...)],
            'metadata_xml' => ['nullable', 'required_without:metadata_url', 'string', 'max:1048576', $this->idpMetadata(...)],
            'email_attribute' => ['nullable', 'string', 'max:255', self::NO_CONTROL],
            'name_attribute' => ['nullable', 'string', 'max:255', self::NO_CONTROL],
            'groups_attribute' => ['nullable', 'string', 'max:255', self::NO_CONTROL],
        ];
    }

    /** @return array<string, list<string>> */
    private function columnRules(): array
    {
        return [
            'jit_enabled' => ['boolean'],
            'force_reauth' => ['boolean'],
            'default_role_id' => ['nullable', 'integer'],
            'default_group_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    private function oidcConfig(array $valid): array
    {
        $flavor = OidcFlavor::from((string) $valid['flavor']);
        $tenant = $flavor === OidcFlavor::Entra ? strtolower((string) $valid['tenant']) : null;
        /** @var list<string> $scopes */
        $scopes = $valid['scopes'] ?? [];

        return [
            'flavor' => $flavor->value,
            'issuer' => match ($flavor) {
                OidcFlavor::Entra => sprintf(ProviderSettings::ENTRA_ISSUER, $tenant),
                OidcFlavor::Google => ProviderSettings::GOOGLE_ISSUER,
                OidcFlavor::Generic => rtrim((string) $valid['issuer'], '/'),
            },
            'tenant' => $tenant,
            'client_id' => trim((string) $valid['client_id']),
            'scopes' => array_values(array_unique(['openid', ...($scopes === [] ? ProviderSettings::DEFAULT_SCOPES : $scopes)])),
            'groups_claim' => filled($valid['groups_claim'] ?? null) ? (string) $valid['groups_claim'] : ProviderSettings::DEFAULT_GROUPS_CLAIM,
            'trust_unverified_email' => $flavor === OidcFlavor::Generic && (bool) ($valid['trust_unverified_email'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    private function samlConfig(array $valid): array
    {
        $config = [];
        foreach (ProviderSettings::SAML_KEYS as $key) {
            $value = Arr::get($valid, $key);
            $config[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $config;
    }

    /** https (and no credentials, query or fragment) unless insecure IdPs are allowed for dev/CI. */
    private function idpUrl(string $attribute, mixed $value, \Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if (! is_array($parts)) {
            return; // the url rule reports it
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $insecure = (bool) config('sso.allow_insecure_idp');
        if (($scheme !== 'https' && (! $insecure || $scheme !== 'http')) || isset($parts['user']) || isset($parts['fragment'])
            || ($attribute === 'issuer' && isset($parts['query']))) {
            $fail(__('sso_admin.provider.errors.insecure_url'));
        }
    }

    /** Pasted IdP metadata: well-formed, no DTD (XXE), a SAML 2.0 EntityDescriptor with an IDPSSODescriptor. */
    private function idpMetadata(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $value) === 1 || ! $this->isIdpMetadata($value)) {
            $fail(__('sso_admin.provider.errors.metadata_xml'));
        }
    }

    private function isIdpMetadata(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $document->documentElement : null;
        $namespace = 'urn:oasis:names:tc:SAML:2.0:metadata';

        return $root instanceof \DOMElement && $root->namespaceURI === $namespace
            && in_array($root->localName, ['EntityDescriptor', 'EntitiesDescriptor'], true)
            && $document->getElementsByTagNameNS($namespace, 'IDPSSODescriptor')->length > 0;
    }

    /** @return list<mixed>|mixed space- or comma-separated scopes become a list */
    private function scopeList(mixed $scopes): mixed
    {
        if (is_string($scopes)) {
            return array_values(array_filter(preg_split('/[\s,]+/', $scopes) ?: [], static fn (string $s): bool => $s !== ''));
        }

        return $scopes ?? [];
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        $names = [];
        foreach (['name', 'client_secret', ...ProviderSettings::OIDC_KEYS, ...ProviderSettings::SAML_KEYS, ...ProviderSettings::COLUMNS] as $key) {
            $names[$key] = (string) __('sso_admin.provider.fields.'.$key);
        }

        return $names;
    }
}
