<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Health;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Skeleton stub (M7, owner A): hourly provider health check (scheduled in routes/console.php). */
#[Description('Check every active SSO provider (discovery, JWKS, SAML metadata)')]
#[Signature('sso:check-providers')]
final class CheckProvidersCommand extends Command
{
    public function handle(): int
    {
        $this->components->info('SSO provider checks are not implemented yet.');

        return self::SUCCESS;
    }
}
