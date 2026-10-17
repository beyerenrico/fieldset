<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Sso\Contracts\HomeRealmDiscovery;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Contracts\ProviderHealthCheck;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Contracts\SsoProviderFactory;
use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;
use App\Domain\Identity\Sso\Discovery\VerifiedDomainDiscovery;
use App\Domain\Identity\Sso\Domains\ConfigTxtRecordResolver;
use App\Domain\Identity\Sso\Domains\DnsTxtRecordResolver;
use App\Domain\Identity\Sso\Domains\RecheckSsoDomainsCommand;
use App\Domain\Identity\Sso\Enforcement\DomainPasswordGate;
use App\Domain\Identity\Sso\Health\CheckProvidersCommand;
use App\Domain\Identity\Sso\Health\FetchProviderHealth;
use App\Domain\Identity\Sso\Health\NotifySsoMisconfiguration;
use App\Domain\Identity\Sso\Providers\DynamicProviderFactory;
use App\Policies\InternalRecordPolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * SSO wiring (BUILD_PROMPT §8.7, ADR 0036): each contract is bound to its final class, the TXT resolver is
 * chosen by `sso.dns.driver` (dns in production, config in dev/CI; tests bind a fake).
 */
final class SsoServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        HomeRealmDiscovery::class => VerifiedDomainDiscovery::class,
        PasswordAuthGate::class => DomainPasswordGate::class,
        SsoProviderFactory::class => DynamicProviderFactory::class,
        ProviderHealthCheck::class => FetchProviderHealth::class,
        SsoMisconfigurationReporter::class => NotifySsoMisconfiguration::class,
    ];

    public function register(): void
    {
        $this->app->bind(TxtRecordResolver::class, static fn (Application $app): TxtRecordResolver => match (config('sso.dns.driver')) {
            'dns' => $app->make(DnsTxtRecordResolver::class),
            'config' => $app->make(ConfigTxtRecordResolver::class),
            default => throw new InvalidArgumentException('Unknown sso.dns.driver; use "dns" or "config".'),
        });
    }

    public function boot(): void
    {
        self::refuseDevSettingsInProduction();

        // Managed only through the provider's settings (IdentityProviderPolicy); never exposed on their own.
        Gate::policy(IdentityProviderMapping::class, InternalRecordPolicy::class);
        Gate::policy(ExternalIdentity::class, InternalRecordPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CheckProvidersCommand::class, RecheckSsoDomainsCommand::class]);
        }
    }

    /**
     * The insecure IdP mode (no SSRF guard) and the config-backed DNS driver are for dev/CI only. The dev
     * compose override sets both, so a production deployment started with it must fail loudly (M7 review).
     */
    public static function refuseDevSettingsInProduction(): void
    {
        if (! app()->isProduction()) {
            return;
        }
        if ((bool) config('sso.allow_insecure_idp') || config('sso.dns.driver') === 'config') {
            throw new \RuntimeException('SSO_ALLOW_INSECURE_IDP and SSO_DNS_DRIVER=config are not allowed in production.');
        }
    }
}
