<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\ConnectionInterface;

/**
 * Credentials a backup carries that must not work again after a restore (plan U17, R64, ADR 0085): sessions,
 * remember-me tokens (a recaller cookie would sign a deleted session back in), password-reset tokens and one-time
 * sign-in links. Everyone signs in again; `fsa login-link` issues a fresh link.
 */
final readonly class StaleCredentials
{
    public function __construct(private ConnectionInterface $target) {}

    /** @return array{sessions: int, remember_tokens: int, password_reset_tokens: int, login_links: int} */
    public function clear(): array
    {
        return [
            'sessions' => $this->target->table('sessions')->delete(),
            'remember_tokens' => $this->target->table('users')->whereNotNull('remember_token')->update(['remember_token' => null]),
            'password_reset_tokens' => $this->target->table('password_reset_tokens')->delete(),
            'login_links' => $this->target->table('login_links')->delete(),
        ];
    }
}
