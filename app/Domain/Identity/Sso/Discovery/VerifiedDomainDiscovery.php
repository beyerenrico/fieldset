<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Discovery;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Data\DiscoveryResult;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Sso\Login\DomainProviderLookup;
use App\Domain\Identity\Sso\Login\EmailDomain;
use App\Domain\Identity\Sso\Login\SsoStartLink;

/**
 * Home-realm discovery at domain level only (ADR 0036): a domain verified by an organization whose provider
 * takes logins (active, or error — the IdP may be back) yields the redirect to `sso.redirect` with the
 * address as login_hint and a start token for this session (SsoStartLink: discovery is the CSRF-protected
 * step a login starts from); anything else is password mode. Users are never looked up, so the answer is the
 * same whether an account exists or not. Throttling is the route's job.
 */
final readonly class VerifiedDomainDiscovery implements HomeRealmDiscovery
{
    public function __construct(private DomainProviderLookup $lookup, private SsoStartLink $links) {}

    public function discover(string $email, SsoClient $client): DiscoveryResult
    {
        $provider = $this->lookup->loginProviderFor($email);
        if (! $provider instanceof IdentityProvider) {
            return DiscoveryResult::password();
        }

        return new DiscoveryResult($provider, $this->links->url($provider, $client, EmailDomain::normalizeEmail($email)), $provider->enforce_sso);
    }
}
