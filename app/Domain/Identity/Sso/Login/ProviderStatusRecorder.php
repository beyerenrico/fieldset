<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use Closure;

/**
 * Provider health bookkeeping (ADR 0036): a provider fault sets status=error with last_error (a draft stays
 * draft, a disabled one disabled), a successful login or check clears it and returns error → active, and a
 * successful test login activates a draft. Status transitions are audited as `sso.provider_status_changed`.
 *
 * Callers hold a model loaded before a slow IdP round trip (a login, the hourly check), while an admin may
 * have changed the provider meanwhile (back to draft after a connection change, disabled). So the next
 * status is computed from the status in the database and written with `WHERE status = <that status>`; a
 * concurrent change makes the update miss and the transition is recomputed from the new status — a draft or
 * disabled provider is never overwritten with error or active from a stale read.
 */
final readonly class ProviderStatusRecorder
{
    private const int ERROR_LIMIT = 1000;

    private const int ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit) {}

    /** @return IdentityProviderStatus the status after the update */
    public function failed(IdentityProvider $provider, string $reason, string $detail): IdentityProviderStatus
    {
        return $this->save($provider, static fn (IdentityProviderStatus $s): IdentityProviderStatus => $s === IdentityProviderStatus::Active ? IdentityProviderStatus::Error : $s, [
            'last_error' => mb_substr($reason.': '.$detail, 0, self::ERROR_LIMIT),
            'last_error_at' => now(),
        ], $reason);
    }

    public function succeeded(IdentityProvider $provider): IdentityProviderStatus
    {
        return $this->save($provider, static fn (IdentityProviderStatus $s): IdentityProviderStatus => $s === IdentityProviderStatus::Error ? IdentityProviderStatus::Active : $s, [
            'last_error' => null, 'last_error_at' => null, 'last_success_at' => now(),
        ], 'ok');
    }

    public function tested(IdentityProvider $provider): IdentityProviderStatus
    {
        $next = static fn (IdentityProviderStatus $s): IdentityProviderStatus => in_array($s, [IdentityProviderStatus::Draft, IdentityProviderStatus::Error], true)
            ? IdentityProviderStatus::Active
            : $s;

        return $this->save($provider, $next, ['last_error' => null, 'last_error_at' => null, 'last_success_at' => now()], 'test_login');
    }

    /**
     * @param  Closure(IdentityProviderStatus): IdentityProviderStatus  $transition
     * @param  array<string, mixed>  $attributes
     */
    private function save(IdentityProvider $provider, Closure $transition, array $attributes, string $reason): IdentityProviderStatus
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $current = $this->currentStatus($provider);
            if (! $current instanceof IdentityProviderStatus) {
                return $provider->status; // deleted meanwhile
            }
            $next = $transition($current);
            $updated = IdentityProvider::query()->withoutTenant()->whereKey($provider->id)->where('status', $current->value)
                ->update(['status' => $next->value, ...$attributes]);
            if ($updated === 1) {
                $provider->forceFill(['status' => $next, ...$attributes])->syncOriginal();
                $this->auditTransition($provider, $current, $next, $reason);

                return $next;
            }
        }

        return $this->currentStatus($provider) ?? $provider->status;
    }

    private function currentStatus(IdentityProvider $provider): ?IdentityProviderStatus
    {
        $status = IdentityProvider::query()->withoutTenant()->whereKey($provider->id)->value('status');

        return $status instanceof IdentityProviderStatus ? $status : (is_string($status) ? IdentityProviderStatus::tryFrom($status) : null);
    }

    private function auditTransition(IdentityProvider $provider, IdentityProviderStatus $from, IdentityProviderStatus $to, string $reason): void
    {
        if ($from !== $to) {
            $this->audit->record('sso.provider_status_changed', $provider, [
                'from' => $from->value, 'to' => $to->value, 'reason' => $reason,
            ]);
        }
    }
}
