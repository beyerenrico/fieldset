<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use App\Domain\Instance\Output\InvalidInput;

/**
 * What a release manifest's signature is checked against (R58, ADR 0070): the keyless production identity, or
 * in harness builds only, a local public key from FSA_TRUST_KEY. A production build refuses FSA_TRUST_KEY with
 * exit 2; there is no skip switch. The trusted root is TUF's (cosign fetches it) unless a test injects a pinned
 * one through the constructor; nothing at runtime can set it.
 */
final readonly class TrustPolicy
{
    public const string TRUST_KEY_ENV = 'FSA_TRUST_KEY';

    private function __construct(
        public ?SignerIdentity $identity,
        public ?string $publicKey,
        public ?string $trustedRoot,
    ) {}

    public static function keyless(SignerIdentity $identity, ?string $trustedRoot = null): self
    {
        return new self($identity, null, $trustedRoot);
    }

    /** A local public key; reachable at runtime only through fromEnvironment() in a harness build. */
    public static function key(string $publicKey): self
    {
        return new self(null, $publicKey, null);
    }

    /**
     * @param  array<string, string>  $env  e.g. getenv()
     *
     * @throws InvalidInput when FSA_TRUST_KEY is set in a production build, or is not an absolute path
     */
    public static function fromEnvironment(array $env, BuildFlavor $flavor): self
    {
        $key = $env[self::TRUST_KEY_ENV] ?? '';
        if ($key === '') {
            return self::keyless(SignerIdentity::production());
        }

        if (! $flavor->acceptsTrustKey()) {
            throw new InvalidInput(
                'FSA_TRUST_KEY is accepted only by release-harness builds; this is a production image.',
                'trust_key_not_allowed',
                'Unset FSA_TRUST_KEY. Production releases are verified against the signer identity built into the image.',
            );
        }

        if (! str_starts_with($key, '/')) {
            throw new InvalidInput('FSA_TRUST_KEY must be an absolute path to a cosign public key.', 'trust_key_invalid');
        }

        return self::key($key);
    }

    public function usesKey(): bool
    {
        return $this->publicKey !== null;
    }
}
