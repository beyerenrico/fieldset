<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Enums\DomainVerification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Daily DNS re-check of every verified SSO domain across organizations (scheduled in routes/console.php).
 * Each domain is checked inside its organization by VerifySsoDomain, which unverifies a domain after
 * `sso.recheck.failures_before_unverify` misses in a row. One failing domain never stops the others.
 */
#[Description('Re-check the DNS TXT records of verified SSO domains')]
#[Signature('sso:recheck-domains')]
final class RecheckSsoDomainsCommand extends Command
{
    public function handle(VerifySsoDomain $verify): int
    {
        $counts = ['verified' => 0, 'missed' => 0, 'unverified' => 0, 'errors' => 0];

        SsoDomain::query()->withoutTenant()->whereNotNull('verified_at')->orderBy('id')
            ->chunkById(100, function (Collection $domains) use ($verify, &$counts): void {
                foreach ($domains as $domain) {
                    $counts[$this->recheck($verify, $domain)]++;
                }
            });

        $this->components->info(sprintf(
            'SSO domains re-checked: %d verified, %d missed, %d unverified, %d errors.',
            $counts['verified'], $counts['missed'], $counts['unverified'], $counts['errors'],
        ));

        return self::SUCCESS;
    }

    /** @return 'verified'|'missed'|'unverified'|'errors' */
    private function recheck(VerifySsoDomain $verify, SsoDomain $domain): string
    {
        try {
            $result = $verify->handle($domain);
        } catch (Throwable $e) {
            report($e);

            return 'errors';
        }

        return match (true) {
            $result === DomainVerification::Verified => 'verified',
            ! $domain->isVerified() => 'unverified',
            $result === DomainVerification::Error => 'errors',
            default => 'missed',
        };
    }
}
