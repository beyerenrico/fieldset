<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

/**
 * Keys of an identity provider's settings (ADR 0036, Data model). The encrypted `config` holds the
 * protocol keys of its type; the column settings travel in the same input array of SaveIdentityProvider.
 *
 * OIDC config: flavor (entra|google|generic), issuer (derived for Entra and Google), tenant (Entra tenant
 * GUID), client_id, scopes (list, always with `openid`), groups_claim (default `groups`),
 * trust_unverified_email (generic only: the audited opt-in to accept a missing email_verified claim).
 * SAML config: metadata_url or metadata_xml, email_attribute, name_attribute, groups_attribute.
 */
final class ProviderSettings
{
    /** Column settings (not encrypted config). */
    public const array COLUMNS = ['jit_enabled', 'default_role_id', 'default_group_id', 'force_reauth'];

    public const array OIDC_KEYS = ['flavor', 'issuer', 'tenant', 'client_id', 'scopes', 'groups_claim', 'trust_unverified_email'];

    public const array SAML_KEYS = ['metadata_url', 'metadata_xml', 'email_attribute', 'name_attribute', 'groups_attribute'];

    /** The connection to the IdP: a change needs a new successful test login (status back to draft). */
    public const array CONNECTION = ['flavor', 'issuer', 'tenant', 'client_id', 'metadata_url', 'metadata_xml'];

    public const string DEFAULT_GROUPS_CLAIM = 'groups';

    /** @var list<string> */
    public const array DEFAULT_SCOPES = ['openid', 'email', 'profile'];

    public const string ENTRA_ISSUER = 'https://login.microsoftonline.com/%s/v2.0';

    public const string GOOGLE_ISSUER = 'https://accounts.google.com';
}
