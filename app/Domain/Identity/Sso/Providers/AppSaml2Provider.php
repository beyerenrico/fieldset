<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Providers;

use Illuminate\Support\Facades\Cache;
use LightSaml\Error\LightSamlValidationException;
use LightSaml\Error\LightSamlXmlException;
use LightSaml\Model\Protocol\AuthnRequest;
use LightSaml\Model\Protocol\SamlMessage;
use SocialiteProviders\Saml2\Provider;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * SAML 2.0 service provider (ADR 0036, SAML) on top of SocialiteProviders\Saml2 and LightSAML 4.7.2 (XSW
 * checks: a signature must reference its enclosing element, and IDs must be unique). Added here:
 * - messages with a DTD (XXE, entity expansion) or NUL bytes (UTF-16 tricks) are refused before parsing,
 *   and any parsed document with a doctype after it;
 * - the assertion audience must name this SP, the response must answer the AuthnRequest of this flow
 *   (InResponseTo, IdP-initiated SSO is not supported), and Destination and bearer Recipient must be our ACS;
 * - the replay check (one-time assertion id) runs after the signature check, atomically, so a forged
 *   response cannot burn a genuine assertion id;
 * - an unsuccessful Status (read before any signature check, so anyone can post one) raises a fixed message:
 *   the StatusMessage is free text from the browser and never reaches the audit or a notification;
 * - the AuthnRequest id is exposed so the flow can bind the response to it; force_reauth sets ForceAuthn.
 */
final class AppSaml2Provider extends Provider
{
    public const string DTD_REFUSED = 'SAML: messages with a document type declaration are refused.';

    public const string AUDIENCE_MISMATCH = 'SAML: the assertion audience does not name this service provider.';

    public const string NOT_IN_RESPONSE = 'SAML: the response does not answer the login request of this flow.';

    public const string REPLAYED = 'The identity provider repeated an assertion id';

    public const string WRONG_RECIPIENT = 'SAML: the response is addressed to another endpoint.';

    public const string UNSUCCESSFUL_STATUS = 'SAML: the identity provider answered with an unsuccessful status.';

    /** One-time assertion ids are remembered this long (longer than any assertion validity). */
    private const int REPLAY_TTL = 86400;

    private ?string $expectedRequestId = null;

    private ?string $lastRequestId = null;

    private bool $forceAuthn = false;

    public function expectingResponseTo(string $requestId): self
    {
        $this->expectedRequestId = $requestId;

        return $this;
    }

    public function forcingAuthentication(bool $force): self
    {
        $this->forceAuthn = $force;

        return $this;
    }

    /** Id of the AuthnRequest sent by redirect(). */
    public function lastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    protected function sendMessage(SamlMessage $message, string $bindingType): HttpFoundationResponse
    {
        if ($message instanceof AuthnRequest) {
            if ($this->forceAuthn) {
                $message->setForceAuthn(true);
            }
            $this->lastRequestId = $message->getID();
        }

        return parent::sendMessage($message, $bindingType);
    }

    protected function receive(): void
    {
        $raw = $this->request->request->all()['SAMLResponse'] ?? null;
        $xml = is_string($raw) ? base64_decode($raw, true) : false;
        if (is_string($xml)) {
            $inflated = @gzinflate($xml);
            $xml = $inflated === false ? $xml : $inflated;
            if (str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
                throw new LightSamlXmlException(self::DTD_REFUSED);
            }
        }

        parent::receive();

        if ($this->messageContext->getDeserializationContext()->getDocument()->doctype !== null) {
            throw new LightSamlXmlException(self::DTD_REFUSED);
        }
    }

    protected function ensureSuccessfulStatus(): void
    {
        if ($this->messageContext->asResponse()?->getStatus()?->isSuccess() !== true) {
            throw new LightSamlValidationException(self::UNSUCCESSFUL_STATUS);
        }
    }

    protected function validateAssertion(): void
    {
        parent::validateAssertion();
        $this->validateAudience();
        $this->validateInResponseTo();
        $this->validateDestination();
    }

    /** Moved behind the signature check, see validateSignature(). */
    protected function validateRepeatedId(): void {}

    protected function validateSignature(): void
    {
        parent::validateSignature();

        $assertion = $this->getFirstAssertion();
        $key = $this->cacheKey('id_'.hash('sha256', $assertion->getIssuer()->getValue().'|'.$assertion->getId()));
        if (! Cache::add($key, true, self::REPLAY_TTL)) {
            throw new LightSamlValidationException(self::REPLAYED);
        }
    }

    private function validateAudience(): void
    {
        $restrictions = $this->getFirstAssertion()->getConditions()?->getAllAudienceRestrictions() ?? [];
        $entityId = $this->getServiceProviderEntityId();

        if ($restrictions === []) {
            throw new LightSamlValidationException(self::AUDIENCE_MISMATCH);
        }
        foreach ($restrictions as $restriction) {
            if (! $restriction->hasAudience($entityId)) {
                throw new LightSamlValidationException(self::AUDIENCE_MISMATCH);
            }
        }
    }

    /**
     * The package's validateRecipient() only filters endpoints by descriptor type (LightSAML ignores the
     * location criteria there), so any Recipient passes: every bearer confirmation must name our ACS, and a
     * Destination, when present, too.
     */
    private function validateDestination(): void
    {
        $acs = $this->getServiceProviderAssertionConsumerUrl();
        $destination = $this->messageContext->asResponse()?->getDestination();
        if ($destination !== null && $destination !== $acs) {
            throw new LightSamlValidationException(self::WRONG_RECIPIENT);
        }

        $confirmations = $this->getFirstAssertion()->getSubject()?->getBearerConfirmations() ?? [];
        if ($confirmations === []) {
            throw new LightSamlValidationException(self::WRONG_RECIPIENT);
        }
        foreach ($confirmations as $confirmation) {
            if ($confirmation->getSubjectConfirmationData()?->getRecipient() !== $acs) {
                throw new LightSamlValidationException(self::WRONG_RECIPIENT);
            }
        }
    }

    private function validateInResponseTo(): void
    {
        $expected = $this->expectedRequestId;
        if ($expected === null || $this->messageContext->asResponse()?->getInResponseTo() !== $expected) {
            throw new LightSamlValidationException(self::NOT_IN_RESPONSE);
        }

        foreach ($this->getFirstAssertion()->getSubject()?->getAllSubjectConfirmations() ?? [] as $confirmation) {
            $inResponseTo = $confirmation->getSubjectConfirmationData()?->getInResponseTo();
            if ($inResponseTo !== null && $inResponseTo !== $expected) {
                throw new LightSamlValidationException(self::NOT_IN_RESPONSE);
            }
        }
    }
}
