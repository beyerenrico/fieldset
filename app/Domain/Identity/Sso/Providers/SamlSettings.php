<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use App\Domain\Identity\Models\IdentityProvider;
use InvalidArgumentException;
use LightSaml\SamlConstants;

/**
 * The SAML settings of an identity provider, read from its encrypted `config` (ADR 0036, SAML).
 *
 * IdP config keys, one of: `metadata_url`; `metadata_xml`; or `idp_entity_id` + `idp_sso_url` +
 * `idp_certificate` (PEM or base64 body). `idp_entity_id` also selects the IdP in an EntitiesDescriptor.
 * Attributes: `email_attribute` (default "email"; the NameID when absent and the NameID format is
 * emailAddress), `name_attribute` (optional), `groups_attribute` (default "groups"); `name_id_format`
 * (requested in the AuthnRequest, default persistent).
 *
 * Identity key (ADR 0036, subject stability): a persistent NameID, or — when `subject_attribute` names a
 * stable attribute such as an immutable user id — that attribute. An email, transient or unspecified NameID
 * is never a key (an email can be reassigned, a transient id changes per login); SamlProfileFactory refuses
 * such an assertion unless `subject_attribute` is set.
 */
final readonly class SamlSettings
{
    public function __construct(
        public ?string $metadata,
        public ?string $entityId,
        public ?string $ssoUrl,
        public ?string $certificate,
        public string $nameIdFormat,
        public string $emailAttribute,
        public ?string $nameAttribute,
        public string $groupsAttribute,
        public ?string $subjectAttribute = null,
    ) {}

    /** @throws InvalidArgumentException on an incomplete configuration */
    public static function of(IdentityProvider $provider): self
    {
        $config = $provider->config;
        $settings = new self(
            self::string($config, 'metadata_url') ?? self::string($config, 'metadata_xml'),
            self::string($config, 'idp_entity_id'),
            self::string($config, 'idp_sso_url'),
            self::string($config, 'idp_certificate'),
            self::string($config, 'name_id_format') ?? SamlConstants::NAME_ID_FORMAT_PERSISTENT,
            self::string($config, 'email_attribute') ?? 'email',
            self::string($config, 'name_attribute'),
            self::string($config, 'groups_attribute') ?? 'groups',
            self::string($config, 'subject_attribute'),
        );

        if ($settings->metadata === null && ($settings->ssoUrl === null || $settings->entityId === null || $settings->certificate === null)) {
            throw new InvalidArgumentException(OidcSettings::CONFIG_ERROR.' SAML needs IdP metadata or entity id, SSO URL and certificate.');
        }

        return $settings;
    }

    /**
     * Config for SocialiteProviders\Saml2\Provider (its key names: `acs` is the IdP's SSO URL there).
     *
     * @return array<string, mixed>
     */
    public function providerConfig(IdentityProvider $provider): array
    {
        return array_filter([
            'metadata' => $this->metadata,
            'acs' => $this->metadata === null ? $this->ssoUrl : null,
            'entityid' => $this->entityId,
            'certificate' => $this->certificate,
            'ttl' => config()->integer('sso.cache_ttl', 3600),
            'sp_entityid' => $provider->samlEntityId(),
            'sp_acs' => ltrim((string) parse_url($provider->samlAcsUrl(), PHP_URL_PATH), '/'),
            'sp_default_binding_method' => SamlConstants::BINDING_SAML2_HTTP_POST,
            'sp_name_id_format' => $this->nameIdFormat,
            'sp_sign_assertions' => true,
            'sp_certificate' => $this->nullable(config('sso.saml.sp_certificate')),
            'sp_private_key' => $this->nullable(config('sso.saml.sp_private_key')),
            // Read by the package as a literal key (Arr::get finds dotted keys first).
            'validation.clock_skew' => config()->integer('sso.clock_skew', 60),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $config */
    private static function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
