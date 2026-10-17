<?php

declare(strict_types=1);

namespace App\Domain\Platform\Impersonation;

use App\Domain\Identity\Models\User;
use App\Http\Middleware\EndRevokedDeviceSession;
use App\Http\Middleware\Security\AdminSessionTimeout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use LogicException;

/**
 * Switches the web guard's user without Login/Logout events: an impersonation is not a sign-in of the target
 * (no `auth.login` entry for them; ADR 0041 audits it as `platform.impersonation_*`). Like SessionGuard::login
 * it stores the password hash checked by AuthenticateSession and regenerates the session id; it also rotates
 * the CSRF token and drops state that belongs to the previous user (device binding, password confirmation, the
 * admin session timers of AdminSessionTimeout).
 */
final readonly class SessionLogin
{
    /** AuthenticateSession's key for the default guard (`password_hash_` + guard name). */
    public const string PASSWORD_HASH_KEY = 'password_hash_web';

    private const array PER_USER_KEYS = [
        EndRevokedDeviceSession::SESSION_KEY, 'auth.password_confirmed_at', 'url.intended', ...AdminSessionTimeout::KEYS,
    ];

    public function __construct(private AuthFactory $auth, private Session $session) {}

    public function switchTo(User $user): void
    {
        $guard = $this->auth->guard('web');
        if (! $guard instanceof SessionGuard) {
            throw new LogicException('The web guard must be a session guard.');
        }

        $this->session->forget(self::PER_USER_KEYS);
        $this->session->put($guard->getName(), $user->getAuthIdentifier());
        $this->session->put(self::PASSWORD_HASH_KEY, $guard->hashPasswordForCookie($user->getAuthPassword()));
        $this->session->migrate(true);
        $this->session->regenerateToken();
        $guard->setUser($user);
    }
}
