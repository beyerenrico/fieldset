<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Data\ExternalProfile;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Providers\SamlSettings;
use InvalidArgumentException;
use LightSaml\Model\Assertion\Assertion;
use LightSaml\Model\Assertion\NameID;
use LightSaml\SamlConstants;
use SocialiteProviders\Saml2\User as SamlUser;

/**
 * Turns a validated, signed SAML assertion into an ExternalProfile (ADR 0036): the email is the configured
 * attribute (or an emailAddress NameID) and counts as verified because the IdP signed it; `amr` carries the
 * AuthnContextClassRef. The subject — the identity key — must be stable (SamlSettings): the configured
 * `subject_attribute`, else a persistent NameID. An email, transient or unspecified NameID is refused as a
 * key, because an email can later belong to someone else and a transient id changes with every login; the
 * refusal is a provider fault (the assertion is signed, so it is the IdP's configuration).
 */
final readonly class SamlProfileFactory
{
    public const string NAME_ID_NOT_PERSISTENT = 'SAML: the NameID is not persistent and no subject attribute is configured.';

    public const string NO_SUBJECT = 'SAML: the assertion has no subject (NameID or configured subject attribute).';

    private const array DISPLAY_NAME = ['name', 'displayName', 'cn'];

    private const array GIVEN_NAME = ['firstName', 'givenName', 'given_name'];

    private const array SURNAME = ['lastName', 'surname', 'sn', 'family_name'];

    public function make(IdentityProvider $provider, SamlSettings $settings, SamlUser $user): ExternalProfile
    {
        $assertion = $user->getAssertion();
        $nameId = $assertion->getSubject()?->getNameID();
        $nameIdValue = trim((string) $nameId?->getValue());
        $attributes = $this->attributes($assertion);
        $subject = $this->subject($settings, $nameId, $attributes);

        $email = $attributes[$settings->emailAttribute][0]
            ?? ($nameId?->getFormat() === SamlConstants::NAME_ID_FORMAT_EMAIL && $nameIdValue !== '' ? $nameIdValue : null)
            ?? $user->getEmail();
        $email = is_string($email) && $email !== '' ? EmailDomain::normalizeEmail($email) : null;

        $claims = ClaimValues::pick($attributes, [$settings->groupsAttribute, ...ClaimValues::mappingClaims($provider)]);
        if (isset($claims[$settings->groupsAttribute])) {
            $claims['groups'] = $claims[$settings->groupsAttribute];
        }
        $claims['amr'] = $this->authnContexts($assertion);

        return new ExternalProfile(
            IdentityProviderType::Saml,
            (string) $assertion->getIssuer()?->getValue(),
            $subject,
            $email,
            $email !== null,
            $this->name($settings, $attributes, $user),
            [...$claims, 'email_verified_by' => $email === null ? null : 'saml'],
        );
    }

    /**
     * The identity key: the configured subject attribute, else a persistent NameID.
     *
     * @param  array<string, list<string>>  $attributes
     *
     * @throws InvalidArgumentException
     */
    private function subject(SamlSettings $settings, ?NameID $nameId, array $attributes): string
    {
        if ($settings->subjectAttribute !== null) {
            $value = trim($attributes[$settings->subjectAttribute][0] ?? '');

            return $value !== '' ? $value : throw new InvalidArgumentException(self::NO_SUBJECT);
        }

        $value = trim((string) $nameId?->getValue());
        if ($value === '') {
            throw new InvalidArgumentException(self::NO_SUBJECT);
        }
        if ($nameId?->getFormat() !== SamlConstants::NAME_ID_FORMAT_PERSISTENT) {
            throw new InvalidArgumentException(self::NAME_ID_NOT_PERSISTENT);
        }

        return $value;
    }

    /** @return array<string, list<string>> attribute name (and friendly name) → values */
    private function attributes(Assertion $assertion): array
    {
        $attributes = [];
        foreach ($assertion->getAllAttributeStatements() as $statement) {
            foreach ($statement->getAllAttributes() as $attribute) {
                $values = ClaimValues::list($attribute->getAllAttributeValues());
                foreach (array_filter([$attribute->getName(), $attribute->getFriendlyName()], is_string(...)) as $name) {
                    $attributes[$name] = array_values(array_unique([...($attributes[$name] ?? []), ...$values]));
                }
            }
        }

        return $attributes;
    }

    /** @return list<string> */
    private function authnContexts(Assertion $assertion): array
    {
        $refs = [];
        foreach ($assertion->getAllAuthnStatements() as $statement) {
            $ref = $statement->getAuthnContext()?->getAuthnContextClassRef();
            if (is_string($ref) && $ref !== '') {
                $refs[] = $ref;
            }
        }

        return ClaimValues::list($refs);
    }

    /**
     * The configured name attribute, else a display-name attribute, else first and last name (Keycloak sends
     * `firstName` and `lastName`; also givenName/sn and the claim-type URIs the package maps).
     *
     * @param  array<string, list<string>>  $attributes
     */
    private function name(SamlSettings $settings, array $attributes, SamlUser $user): ?string
    {
        $first = fn (array $names): ?string => array_values(array_filter(array_map(static fn (string $n): ?string => $attributes[$n][0] ?? null, $names)))[0] ?? null;
        $name = ($settings->nameAttribute === null ? null : ($attributes[$settings->nameAttribute][0] ?? null))
            ?? $user->getName()
            ?? $first(self::DISPLAY_NAME)
            ?? trim(implode(' ', array_filter([
                $first(self::GIVEN_NAME) ?? $user->getFirstName(),
                $first(self::SURNAME) ?? $user->getLastName(),
            ], is_string(...))));

        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null;
    }
}
