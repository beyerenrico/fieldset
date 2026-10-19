<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * R64 (plan U17, KTD18, ADR 0085): restore and rollback re-apply the security state that changed after the backup.
 * The pre-restore database (`source`, read only) is compared with the restored one (`target`) before the swap: account
 * erasures, deactivations, password hashes and MFA settings (AccountCarry), then device revocations and wipes,
 * membership deactivations, role and permission removals, deleted API tokens and revoked operators (AccessCarry), then
 * SSO, group, form access, organization and webhook removals (ScopeCarry, SsoCarry). CarryScope decides what the
 * state difference may carry: everything when the source descends from the backup (mode state), only timestamped or
 * audited changes after the backup when the source was itself restored after it (mode audit), and never a child row
 * whose parent the source lost (cascade guard).
 */
final readonly class SecurityStateCarrier
{
    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target, private ?CarbonImmutable $backupCreatedAt = null) {}

    /**
     * @return array{carried: array<string, mixed>, carry_mode: string, carry_skipped: array<string, int>, cascade_skipped: array<string, int>}
     *                                                                                                                                          `carried`: counts per item and the data-export directories of carried erasures
     */
    public function carry(): array
    {
        return $this->target->transaction(function (): array {
            $scope = CarryScope::detect($this->source, $this->target, $this->backupCreatedAt);
            if ($scope->afterReset()) {
                return ['carried' => [], ...$scope->report()]; // matching rows by ID would give one person's state to another
            }
            $carried = [
                ...new AccountCarry($this->source, $this->target, $scope)->carry(),
                ...new AccessCarry($this->source, $this->target, $scope)->carry(),
                ...new ScopeCarry($this->source, $this->target, $scope)->carry(),
            ];

            return ['carried' => $carried, ...$scope->report()];
        });
    }
}
