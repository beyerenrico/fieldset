<?php

declare(strict_types=1);

namespace App\Domain\Platform\Impersonation;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;

/**
 * Ends an impersonation (ADR 0041) and audits `platform.impersonation_ended` with `ended_by`:
 * - `operator` (exit button) and `expired` (time limit) restore the operator's own session;
 * - `logout` (the target session signed out or no longer matches) only records the end.
 * An operator that was deleted or deactivated meanwhile is not restored: the session is signed out.
 */
final readonly class EndImpersonation
{
    public const string BY_OPERATOR = 'operator';

    public const string BY_EXPIRY = 'expired';

    public const string BY_LOGOUT = 'logout';

    public function __construct(
        private ImpersonationStore $store,
        private SessionLogin $login,
        private AuditLogger $audit,
        private TenantContext $tenant,
        private AuthFactory $auth,
        private Session $session,
    ) {}

    /** @return User|null the restored operator */
    public function handle(Impersonation $impersonation, string $endedBy): ?User
    {
        $this->store->forget();
        $operator = User::query()->find($impersonation->operatorId);
        $target = User::query()->find($impersonation->targetId);

        $properties = [
            ...$impersonation->auditProperties($operator, $target),
            'ended_by' => $endedBy,
            'duration_seconds' => (int) $impersonation->startedAt->diffInSeconds(CarbonImmutable::now(), true),
        ];
        $this->tenant->run($impersonation->organizationId, fn (): mixed => $this->audit->record(
            'platform.impersonation_ended', $target, $properties, $operator,
        ));

        if ($endedBy === self::BY_LOGOUT) {
            return null;
        }

        if ($operator === null || ! $operator->isActive()) {
            $this->auth->guard('web')->logout();
            $this->session->invalidate();

            return null;
        }

        $this->login->switchTo($operator);

        return $operator;
    }
}
