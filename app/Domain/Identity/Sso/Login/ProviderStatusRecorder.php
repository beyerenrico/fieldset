<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;

/**
 * Provider health bookkeeping (ADR 0036): a provider fault sets status=error with last_error (a draft stays
 * draft, a disabled one disabled), a successful login or check clears it and returns error → active, and a
 * successful test login activates a draft. Status transitions are audited as `sso.provider_status_changed`.
 */
final readonly class ProviderStatusRecorder
{
    private const int ERROR_LIMIT = 1000;

    public function __construct(private AuditLogger $audit) {}

    public function failed(IdentityProvider $provider, string $reason, string $detail): void
    {
        $next = $provider->status === IdentityProviderStatus::Active ? IdentityProviderStatus::Error : $provider->status;
        $this->save($provider, $next, [
            'last_error' => mb_substr($reason.': '.$detail, 0, self::ERROR_LIMIT),
            'last_error_at' => now(),
        ], $reason);
    }

    public function succeeded(IdentityProvider $provider): void
    {
        $next = $provider->status === IdentityProviderStatus::Error ? IdentityProviderStatus::Active : $provider->status;
        $this->save($provider, $next, ['last_error' => null, 'last_error_at' => null, 'last_success_at' => now()], 'ok');
    }

    public function tested(IdentityProvider $provider): void
    {
        $next = in_array($provider->status, [IdentityProviderStatus::Draft, IdentityProviderStatus::Error], true)
            ? IdentityProviderStatus::Active
            : $provider->status;
        $this->save($provider, $next, ['last_error' => null, 'last_error_at' => null, 'last_success_at' => now()], 'test_login');
    }

    /** @param  array<string, mixed>  $attributes */
    private function save(IdentityProvider $provider, IdentityProviderStatus $next, array $attributes, string $reason): void
    {
        $previous = $provider->status;
        $provider->forceFill(['status' => $next, ...$attributes])->save();

        if ($previous !== $next) {
            $this->audit->record('sso.provider_status_changed', $provider, [
                'from' => $previous->value, 'to' => $next->value, 'reason' => $reason,
            ]);
        }
    }
}
