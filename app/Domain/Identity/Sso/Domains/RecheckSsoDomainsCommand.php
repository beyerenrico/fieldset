<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Skeleton stub (M7, owner B): daily DNS re-check of verified SSO domains (scheduled in routes/console.php). */
#[Description('Re-check the DNS TXT records of verified SSO domains')]
#[Signature('sso:recheck-domains')]
final class RecheckSsoDomainsCommand extends Command
{
    public function handle(): int
    {
        $this->components->info('SSO domain re-checks are not implemented yet.');

        return self::SUCCESS;
    }
}
