<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\ConnectionInterface;

/**
 * R64 (plan U17, KTD18, ADR 0085): restore and rollback re-apply the security state that changed after the backup.
 * The pre-restore database (`source`, read only) is compared with the restored one (`target`) before the swap: account
 * erasures, deactivations, password hashes and MFA settings (AccountCarry), then device revocations and wipes,
 * membership deactivations, role and permission removals, deleted API tokens and revoked operators (AccessCarry).
 */
final readonly class SecurityStateCarrier
{
    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target) {}

    /** @return array<string, mixed> counts per item, and the data-export directories of carried erasures */
    public function carry(): array
    {
        return $this->target->transaction(fn (): array => [
            ...new AccountCarry($this->source, $this->target)->carry(),
            ...new AccessCarry($this->source, $this->target)->carry(),
        ]);
    }
}
