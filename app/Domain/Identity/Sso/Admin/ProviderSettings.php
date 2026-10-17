<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use LightSaml\SamlConstants;

/**
 * Keys of an identity provider's settings (ADR 0036, Data model). The encrypted `config` holds the
 * protocol keys of its type; the column settings travel in the same input array of SaveIdentityProvider.
 *
 * OIDC config: flavor (entra|google|generic), issuer (derived for Entra and Google), tenant (Entra tenant
 * GUID), client_id, scopes (list, always with `openid`), groups_claim (default `groups`),
 * trust_unverified_email (generic only: the audited opt-in to accept a missing email_verified claim).
 * SAML config: metadata_url or metadata_xml, email_attribute, name_attribute, groups_attribute,
 * name_id_format (a NameID format URN; none means persistent at runtime), subject_attribute (an attribute
 * with a stable id; required unless the NameID is persistent, SamlSettings).
 */
final class ProviderSettings
{
    /** Column settings (not encrypted config). */
    public const array COLUMNS = ['jit_enabled', 'default_role_id', 'default_group_id', 'force_reauth'];

    public const array OIDC_KEYS = ['flavor', 'issuer', 'tenant', 'client_id', 'scopes', 'groups_claim', 'trust_unverified_email'];

    public const array SAML_KEYS = ['metadata_url', 'metadata_xml', 'email_attribute', 'name_attribute', 'groups_attribute', 'name_id_format', 'subject_attribute'];

    /**
     * Keys the runtime reads (OidcSettings, SamlSettings) but the admin form never writes: the discovery URL
     * override, the alias of trust_unverified_email and the SAML IdP given without metadata. Saving through the
     * admin drops them, which counts as a change.
     */
    public const array RUNTIME_ONLY_KEYS = ['discovery_url', 'allow_unverified_email', 'idp_entity_id', 'idp_sso_url', 'idp_certificate'];

    /** The connection to the IdP: a change needs a new successful test login (status back to draft). */
    public const array CONNECTION = [
        'flavor', 'issuer', 'tenant', 'client_id', 'metadata_url', 'metadata_xml',
        'discovery_url', 'idp_entity_id', 'idp_sso_url', 'idp_certificate',
    ];

    /**
     * Settings that decide who an IdP answer signs in as, or with which role: changing them needs an
     * Owner-level actor (organization.delete), like the break-glass rule. With the connection, an Admin
     * could otherwise point SSO at their own IdP (or an attribute they control) and sign in as an Owner.
     * Config keys, the `type` and the JIT default role (column).
     */
    public const array OWNER_ONLY = [
        ...self::CONNECTION, 'trust_unverified_email', 'allow_unverified_email', 'groups_claim',
        'email_attribute', 'name_attribute', 'groups_attribute', 'name_id_format', 'subject_attribute',
    ];

    public const array OWNER_ONLY_COLUMNS = ['type', 'default_role_id'];

    public const string DEFAULT_GROUPS_CLAIM = 'groups';

    /** @var list<string> */
    public const array DEFAULT_SCOPES = ['openid', 'email', 'profile'];

    public const string ENTRA_ISSUER = 'https://login.microsoftonline.com/%s/v2.0';

    public const string GOOGLE_ISSUER = 'https://accounts.google.com';

    /** Issuer hosts that need their own flavor (generic OIDC skips the Entra tid / Google hd checks). */
    public const array GOOGLE_HOSTS = ['accounts.google.com'];

    public const array MICROSOFT_HOSTS = ['login.microsoftonline.com', 'sts.windows.net'];

    /** NameID formats offered for SAML, by short name; persistent is the default for new providers. */
    public const array NAME_ID_FORMATS = [
        'persistent' => SamlConstants::NAME_ID_FORMAT_PERSISTENT,
        'email' => SamlConstants::NAME_ID_FORMAT_EMAIL,
        'transient' => SamlConstants::NAME_ID_FORMAT_TRANSIENT,
        'unspecified' => SamlConstants::NAME_ID_FORMAT_UNSPECIFIED,
    ];

    /**
     * Only a persistent NameID is a stable identity key; with any other format a `subject_attribute` must name
     * a stable id, or SamlProfileFactory refuses every sign-in (an email can be reassigned, a transient id
     * changes per login).
     */
    public static function isStableNameIdFormat(?string $format): bool
    {
        return $format === null || $format === SamlConstants::NAME_ID_FORMAT_PERSISTENT;
    }

    /**
     * Fingerprint keys (SaveIdentityProvider) that only an Owner-level actor may change.
     *
     * @return list<string>
     */
    public static function ownerOnlyFingerprintKeys(): array
    {
        return [...self::OWNER_ONLY_COLUMNS, ...array_map(static fn (string $key): string => 'config.'.$key, self::OWNER_ONLY)];
    }
}
