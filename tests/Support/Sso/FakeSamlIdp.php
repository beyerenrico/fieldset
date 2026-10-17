<?php

declare(strict_types=1);

namespace Tests\Support\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Tenancy\TenantContext;
use DateTime;
use DOMDocument;
use DOMElement;
use DOMXPath;
use LightSaml\Builder\EntityDescriptor\SimpleEntityDescriptorBuilder;
use LightSaml\Credential\KeyHelper;
use LightSaml\Credential\X509Certificate;
use LightSaml\Helper;
use LightSaml\Model\Assertion\Assertion;
use LightSaml\Model\Assertion\Attribute;
use LightSaml\Model\Assertion\AttributeStatement;
use LightSaml\Model\Assertion\AudienceRestriction;
use LightSaml\Model\Assertion\AuthnContext;
use LightSaml\Model\Assertion\AuthnStatement;
use LightSaml\Model\Assertion\Conditions;
use LightSaml\Model\Assertion\Issuer;
use LightSaml\Model\Assertion\NameID;
use LightSaml\Model\Assertion\Subject;
use LightSaml\Model\Assertion\SubjectConfirmation;
use LightSaml\Model\Assertion\SubjectConfirmationData;
use LightSaml\Model\Context\SerializationContext;
use LightSaml\Model\Protocol\Response;
use LightSaml\Model\Protocol\Status;
use LightSaml\Model\Protocol\StatusCode;
use LightSaml\Model\XmlDSig\SignatureWriter;
use LightSaml\SamlConstants;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * A fake SAML IdP: metadata with a self-signed certificate generated once per test run, and Responses whose
 * assertion is signed with LightSAML (or with a foreign key, or not at all). Also builds the attack fixtures
 * (XML signature wrapping, DTD/XXE). The AuthnRequest is read back from the HTTP-Redirect URL.
 */
final class FakeSamlIdp
{
    public const string ENTITY_ID = 'https://saml-idp.example.test/realms/acme';

    public const string SSO_URL = 'https://saml-idp.example.test/realms/acme/protocol/saml';

    /** @var array<string, array{cert: string, key: string}> */
    private static array $credentials = [];

    public function metadataXml(): string
    {
        $builder = new SimpleEntityDescriptorBuilder(self::ENTITY_ID, null, self::SSO_URL, self::certificate('main'), [], [SamlConstants::BINDING_SAML2_HTTP_REDIRECT], ['signing']);
        $context = new SerializationContext;
        $builder->get()->serialize($context->getDocument(), $context);

        return (string) $context->getDocument()->saveXML();
    }

    /**
     * An active SAML provider of the organization configured with this IdP's metadata XML (JIT on).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $config
     */
    public function provider(Organization $organization, array $attributes = [], array $config = []): IdentityProvider
    {
        return app(TenantContext::class)->run($organization, fn (): IdentityProvider => IdentityProvider::factory()->saml()->active()->create([
            'type' => IdentityProviderType::Saml,
            'config' => ['metadata_xml' => $this->metadataXml(), 'email_attribute' => 'email', 'groups_attribute' => 'groups', ...$config],
            'jit_enabled' => true,
            ...$attributes,
        ]));
    }

    /** @return array{id: string, relay_state: string, acs: string, force_authn: bool} the AuthnRequest of a redirect */
    public function authnRequest(string $redirectUrl): array
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);
        $xml = gzinflate((string) base64_decode(is_string($query['SAMLRequest'] ?? null) ? $query['SAMLRequest'] : '', true));
        $document = new DOMDocument;
        $document->loadXML((string) $xml);
        $root = $document->documentElement ?? throw new RuntimeException('No AuthnRequest.');

        return [
            'id' => $root->getAttribute('ID'),
            'relay_state' => is_string($query['RelayState'] ?? null) ? $query['RelayState'] : '',
            'acs' => $root->getAttribute('AssertionConsumerServiceURL'),
            'force_authn' => $root->getAttribute('ForceAuthn') === 'true',
        ];
    }

    /**
     * A base64 SAMLResponse answering the request.
     *
     * @param  array{id: string, acs: string}  $request
     * @param  array<string, string|list<string>>  $attributes
     * @param  array{name_id?: string, issuer?: string, audience?: string, in_response_to?: string, recipient?: string, sign?: string|null, not_on_or_after?: string, assertion_id?: string}  $options
     */
    public function response(array $request, string $spEntityId, array $attributes = [], array $options = []): string
    {
        $issuer = $options['issuer'] ?? self::ENTITY_ID;
        $inResponseTo = $options['in_response_to'] ?? $request['id'];
        $assertion = $this->assertion($request, $spEntityId, $attributes, $options);
        $sign = array_key_exists('sign', $options) ? $options['sign'] : 'main';
        if ($sign !== null) {
            $assertion->setSignature(new SignatureWriter(self::certificate($sign), $this->key($sign)));
        }

        $response = (new Response)
            ->setInResponseTo($inResponseTo)
            ->setStatus(new Status(new StatusCode(SamlConstants::STATUS_SUCCESS)))
            ->addAssertion($assertion)
            ->setID(Helper::generateID())
            ->setIssueInstant(new DateTime)
            ->setDestination($request['acs'])
            ->setIssuer(new Issuer($issuer));

        $context = new SerializationContext;
        $response->serialize($context->getDocument(), $context);

        return base64_encode((string) $context->getDocument()->saveXML());
    }

    /**
     * XML signature wrapping: an evil, unsigned assertion for another subject is placed first (where the SP
     * reads it), carrying a copy of the genuine signature, while the genuine assertion is kept behind it.
     */
    public static function wrapSignature(string $response, string $evilNameId): string
    {
        $document = self::document($response);
        $xpath = self::xpath($document);
        $genuine = $xpath->query('//saml:Assertion')->item(0);
        if (! $genuine instanceof DOMElement) {
            throw new RuntimeException('No assertion.');
        }
        $evil = $genuine->cloneNode(true);
        if (! $evil instanceof DOMElement) {
            throw new RuntimeException('Cannot clone.');
        }
        $nameId = $xpath->query('.//saml:Subject/saml:NameID', $evil)->item(0);
        if ($nameId !== null) {
            $nameId->nodeValue = $evilNameId;
        }
        foreach ($xpath->query('.//saml:Attribute[@Name="email"]/saml:AttributeValue', $evil) ?: [] as $value) {
            $value->nodeValue = $evilNameId;
        }
        $genuine->parentNode?->insertBefore($evil, $genuine);

        return base64_encode((string) $document->saveXML());
    }

    /** Prepends a DTD with an external entity (XXE) and references it in the email attribute. */
    public static function withExternalEntity(string $response, string $file): string
    {
        $xml = (string) base64_decode($response, true);
        $xml = (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $xml);
        $doctype = '<?xml version="1.0"?>'."\n".'<!DOCTYPE samlp:Response [<!ENTITY xxe SYSTEM "file://'.$file.'">]>'."\n";
        $xml = (string) preg_replace('#(<saml:Attribute Name="email"[^>]*>\s*<saml:AttributeValue[^>]*>)[^<]*#', '$1&xxe;', $xml, 1);

        return base64_encode($doctype.$xml);
    }

    /**
     * @param  array{id: string, acs: string}  $request
     * @param  array<string, string|list<string>>  $attributes
     * @param  array{name_id?: string, issuer?: string, audience?: string, in_response_to?: string, recipient?: string, not_on_or_after?: string, assertion_id?: string}  $options
     */
    private function assertion(array $request, string $spEntityId, array $attributes, array $options): Assertion
    {
        $notOnOrAfter = new DateTime($options['not_on_or_after'] ?? '+5 minutes');
        $statement = new AttributeStatement;
        foreach ($attributes as $name => $value) {
            $statement->addAttribute(new Attribute($name, $value));
        }

        return (new Assertion)
            ->setId($options['assertion_id'] ?? Helper::generateID())
            ->setIssueInstant(new DateTime)
            ->setIssuer(new Issuer($options['issuer'] ?? self::ENTITY_ID))
            ->setSubject((new Subject)
                ->setNameID(new NameID($options['name_id'] ?? 'jane@acme.example', SamlConstants::NAME_ID_FORMAT_EMAIL))
                ->addSubjectConfirmation((new SubjectConfirmation)
                    ->setMethod(SamlConstants::CONFIRMATION_METHOD_BEARER)
                    ->setSubjectConfirmationData((new SubjectConfirmationData)
                        ->setInResponseTo($options['in_response_to'] ?? $request['id'])
                        ->setNotOnOrAfter($notOnOrAfter)
                        ->setRecipient($options['recipient'] ?? $request['acs']))))
            ->setConditions((new Conditions)
                ->setNotBefore(new DateTime('-1 minute'))
                ->setNotOnOrAfter($notOnOrAfter)
                ->addItem(new AudienceRestriction([$options['audience'] ?? $spEntityId])))
            ->addItem((new AuthnStatement)
                ->setAuthnInstant(new DateTime)
                ->setSessionIndex(Helper::generateID())
                ->setAuthnContext((new AuthnContext)->setAuthnContextClassRef(SamlConstants::AUTHN_CONTEXT_PASSWORD_PROTECTED_TRANSPORT)))
            ->addItem($statement);
    }

    private static function document(string $response): DOMDocument
    {
        $document = new DOMDocument;
        $document->loadXML((string) base64_decode($response, true));

        return $document;
    }

    private static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('saml', SamlConstants::NS_ASSERTION);
        $xpath->registerNamespace('samlp', SamlConstants::NS_PROTOCOL);

        return $xpath;
    }

    public static function certificate(string $name): X509Certificate
    {
        return (new X509Certificate)->loadPem(self::credential($name)['cert']);
    }

    private function key(string $name): XMLSecurityKey
    {
        return KeyHelper::createPrivateKey(self::credential($name)['key'], '', false, XMLSecurityKey::RSA_SHA256);
    }

    /** @return array{cert: string, key: string} */
    private static function credential(string $name): array
    {
        if (! isset(self::$credentials[$name])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) ?: throw new RuntimeException('No key.');
            $csr = openssl_csr_new(['commonName' => 'fake-saml-idp-'.$name], $key, ['digest_alg' => 'sha256']);
            $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']) ?: throw new RuntimeException('No certificate.');
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem);
            self::$credentials[$name] = ['cert' => (string) $certPem, 'key' => (string) $keyPem];
        }

        return self::$credentials[$name];
    }
}
