<?php

declare(strict_types=1);

namespace App\Domain\Platform\Listeners;

use App\Domain\Platform\Impersonation\EndImpersonation;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * A real sign-in or sign-out inside an impersonated session ends the impersonation without restoring
 * the operator (`ended_by=logout`, ADR 0041). Impersonation's own guard switches fire no auth events.
 */
final readonly class EndImpersonationOnAuthChange
{
    public function __construct(private ImpersonationStore $store, private EndImpersonation $end) {}

    public function handle(Login|Logout $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $impersonation = $this->store->current();
        if ($impersonation instanceof Impersonation) {
            $this->end->handle($impersonation, EndImpersonation::BY_LOGOUT);
        }
    }
}
