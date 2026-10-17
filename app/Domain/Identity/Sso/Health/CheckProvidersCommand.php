<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Health;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\ProviderHealthCheck;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Login\DomainProviderLookup;
use App\Domain\Identity\Sso\Login\ProviderStatusRecorder;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hourly provider health check (ADR 0036; scheduled in routes/console.php): every provider that takes logins
 * (active or error) is checked; a failure sets status=error with last_error and reaches the
 * SsoMisconfigurationReporter (which throttles), a success clears the error and returns error → active.
 * A check takes seconds per provider: the status is written conditionally (ProviderStatusRecorder), and a
 * provider an admin drafted or disabled meanwhile keeps that status and is not reported.
 */
#[Description('Check every active SSO provider (discovery, JWKS, SAML metadata)')]
#[Signature('sso:check-providers')]
final class CheckProvidersCommand extends Command
{
    public function handle(ProviderHealthCheck $check, ProviderStatusRecorder $status, SsoMisconfigurationReporter $reporter, TenantContext $tenant): int
    {
        $providers = IdentityProvider::query()->withoutTenant()
            ->whereIn('status', array_map(static fn (IdentityProviderStatus $s): string => $s->value, DomainProviderLookup::LOGIN_STATUSES))
            ->orderBy('id')->get();

        if ($providers->isEmpty()) {
            $this->components->info(__('sso.command.none'));

            return self::SUCCESS;
        }

        foreach ($providers as $provider) {
            $tenant->run($provider->organization_id, function () use ($provider, $check, $status, $reporter): void {
                $health = $check->check($provider);
                if ($health->ok) {
                    $status->succeeded($provider);
                    $this->components->twoColumnDetail($provider->uuid, __('sso.command.ok'));

                    return;
                }

                $reason = $health->reason ?? 'unknown';
                $next = $status->failed($provider, $reason, (string) $health->detail);
                if (in_array($next, DomainProviderLookup::LOGIN_STATUSES, true)) {
                    $reporter->report($provider, $reason, (string) $health->detail);
                }
                $this->components->twoColumnDetail($provider->uuid, __('sso.command.failed', ['reason' => $reason]));
            });
        }

        return self::SUCCESS;
    }
}
