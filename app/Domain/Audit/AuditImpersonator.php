<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use Illuminate\Support\Facades\Auth;

/**
 * The platform operator behind the current request while they impersonate a member (ADR 0041). EnrichActivity
 * stores it as the `impersonator` property ({id, email}) of every audit entry the request writes, inside the
 * hash chain (ASVS V7.1.4: the entry names who really acted, not only the impersonated account).
 *
 * Only while the session's signed-in user is the impersonation's target, and never on an entry caused by
 * someone else (a sign-in of another account in the same session). Console runs have no session.
 */
final class AuditImpersonator
{
    public const string PROPERTY = 'impersonator';

    /** @return array{id: int, email: string|null}|null */
    public static function current(mixed $causerId): ?array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }
        $impersonation = app(ImpersonationStore::class)->current();
        $userId = $impersonation instanceof Impersonation ? Auth::guard('web')->id() : null;
        if (! $impersonation instanceof Impersonation || ! is_numeric($userId) || (int) $userId !== $impersonation->targetId) {
            return null;
        }
        if (is_numeric($causerId) && (int) $causerId !== $impersonation->targetId) {
            return null;
        }
        $email = User::query()->whereKey($impersonation->operatorId)->value('email');

        return ['id' => $impersonation->operatorId, 'email' => is_string($email) ? $email : null];
    }
}
