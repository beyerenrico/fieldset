<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
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
 *   Google gets the Google issuer; a generic issuer at Google or Microsoft is refused (their flavors check
 *   the `hd` / `tid` claims, generic OIDC would not);
 * - an OIDC client secret is required while none is stored;
 * - SAML needs a metadata URL or pasted IdP metadata XML (no DTDs, an IDPSSODescriptor); the NameID format
 *   is one of ProviderSettings::NAME_ID_FORMATS, persistent by default for a new SAML provider (an older one
 *   without a format keeps none, which is persistent at runtime); any other format needs a subject attribute
 *   with a stable id (the identity key, SamlSettings);
 * - the default role and group belong to the organization, and a new default role is one the actor may
 *   assign (SsoTargets).
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
    public function validate(Organization $org, IdentityProviderType $type, string $name, array $input, ?string $newSecret, ?IdentityProvider $existing, ?User $actor = null): array
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
            ->after(fn (ValidatorInstance $v) => $this->targets->check($v, $org, $data, $actor, $existing?->default_role_id))
            ->after(fn (ValidatorInstance $v) => $this->checkFlavorForIssuer($v, $type, $data))
            ->after(fn (ValidatorInstance $v) => $this->checkSamlSubject($v, $type, $data))
            ->validate();

        return [
            'config' => $type === IdentityProviderType::Oidc ? $this->oidcConfig($valid) : $this->samlConfig($valid, $existing),
            'columns' => [
                'jit_enabled' => (bool) ($valid['jit_enabled'] ?? false),
                'force_reauth' => (bool) ($valid['force_reauth'] ?? false),
                'default_role_id' => isset($valid['default_role_id']) ? (int) $valid['default_role_id'] : null,
                'default_group_id' => isset($valid['default_group_id']) ? (int) $valid['default_group_id'] : null,
            ],
        ];
    }

    /**
     * The stored config in the normalized form validate() would produce (defaults filled in, issuer derived),
     * so a re-save of unchanged values compares equal even for providers stored before a default existed.
     *
     * @return array<string, mixed>
     */
    public function normalizedConfig(IdentityProvider $provider): array
    {
        $config = $provider->config;
        // Runtime-only keys stay as stored: a save drops them, and that is a change (ProviderSettings).
        $runtimeOnly = array_intersect_key($config, array_flip(ProviderSettings::RUNTIME_ONLY_KEYS));
        if ($provider->type === IdentityProviderType::Saml) {
            return [...$this->samlConfig($config, $provider), ...$runtimeOnly];
        }
        $config['scopes'] = is_array($config['scopes'] ?? null) ? array_values(array_filter($config['scopes'], is_string(...))) : [];

        return [...$this->oidcConfig($config), ...$runtimeOnly];
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
            'name_id_format' => ['nullable', 'string', Rule::in(array_values(ProviderSettings::NAME_ID_FORMATS))],
            'subject_attribute' => ['nullable', 'string', 'max:255', self::NO_CONTROL],
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
        $flavor = OidcFlavor::tryFrom((string) ($valid['flavor'] ?? '')) ?? OidcFlavor::Generic;
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
    private function samlConfig(array $valid, ?IdentityProvider $existing): array
    {
        $config = [];
        foreach (ProviderSettings::SAML_KEYS as $key) {
            $value = Arr::get($valid, $key);
            $config[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        if ($config['name_id_format'] === null && $existing?->type !== IdentityProviderType::Saml) {
            $config['name_id_format'] = ProviderSettings::NAME_ID_FORMATS['persistent'];
        }

        return $config;
    }

    /**
     * A NameID that is not persistent is no identity key: then a subject attribute must name a stable id.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkSamlSubject(ValidatorInstance $v, IdentityProviderType $type, array $data): void
    {
        $format = is_string($data['name_id_format'] ?? null) && $data['name_id_format'] !== '' ? $data['name_id_format'] : null;
        if ($type === IdentityProviderType::Saml && ! $v->errors()->has('name_id_format') && ! ProviderSettings::isStableNameIdFormat($format)
            && blank($data['subject_attribute'] ?? null)) {
            $v->errors()->add('subject_attribute', __('sso_admin.provider.errors.subject_attribute_required'));
        }
    }

    /**
     * Google and Microsoft issuers need their flavor: generic OIDC skips the Google `hd` and Entra `tid`
     * checks, so any Google or Microsoft account could sign in.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkFlavorForIssuer(ValidatorInstance $v, IdentityProviderType $type, array $data): void
    {
        if ($type !== IdentityProviderType::Oidc || ($data['flavor'] ?? null) !== OidcFlavor::Generic->value || $v->errors()->has('issuer')) {
            return;
        }
        $host = is_string($data['issuer'] ?? null) ? strtolower((string) parse_url($data['issuer'], PHP_URL_HOST)) : '';
        if (in_array($host, ProviderSettings::GOOGLE_HOSTS, true)) {
            $v->errors()->add('issuer', __('sso_admin.provider.errors.use_google_flavor'));
        } elseif (in_array($host, ProviderSettings::MICROSOFT_HOSTS, true)) {
            $v->errors()->add('issuer', __('sso_admin.provider.errors.use_entra_flavor'));
        }
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
