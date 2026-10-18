<?php

declare(strict_types=1);

namespace App\Domain\Platform\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\PlatformAdmins;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Who may open the mail catcher behind the edge in domain mode (plan R66, ADR 0075). The catcher holds every
 * tenant's password-reset and invitation links, so it needs more than an /admin session: an active platform
 * operator whose session signed in on /platform (PlatformSession mark, within platform.session_hours) and who has
 * MFA confirmed. A session whose stored password hash no longer matches (password changed elsewhere) gets nothing,
 * as Laravel's AuthenticateSession would log it out.
 *
 * The session is only read: the user id comes straight from the web guard's session key, never through the guard,
 * whose remember-me recaller would sign a user in (and fire login events) inside a forward_auth subrequest.
 * Every session's first opening is audited as `platform.catcher_opened`; an atomic cache entry keeps the many
 * parallel catcher requests of one session to a single entry without writing the session.
 */
final readonly class CatcherAccess
{
    private const string OPENED_KEY = 'instance:catcher_opened:';

    public function __construct(private PlatformAdmins $admins, private AuditLogger $audit) {}

    /** The operator the session belongs to when it may open the catcher, else null. */
    public function allowedUser(Session $session): ?User
    {
        $guard = Auth::guard('web');
        if (! $guard instanceof SessionGuard) {
            return null;
        }
        $id = $session->get($guard->getName());
        $user = is_int($id) || is_string($id) ? User::query()->find($id) : null;
        if (! $user instanceof User || ! $this->admins->check($user) || ! new PlatformSession($session)->isValid($user)) {
            return null;
        }

        return $this->hasMfa($user) && $this->passwordUnchanged($guard, $session, $user) ? $user : null;
    }

    /** Audits the first opening of this session; later requests of the same session write nothing. */
    public function recordOpening(User $user, string $sessionId): void
    {
        $hours = max(1, (int) config('platform.session_hours', 12));
        if (Cache::add(self::OPENED_KEY.hash('sha256', $sessionId), true, now()->addHours($hours))) {
            $this->audit->record('platform.catcher_opened', $user, ['email' => $user->email], $user);
        }
    }

    /** Filament's app MFA (shared TOTP, ADR 0016) counts once its secret is confirmed. */
    private function hasMfa(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /** AuthenticateSession stores `password_hash_web` as an HMAC of the hash (or, older, the hash itself). */
    private function passwordUnchanged(SessionGuard $guard, Session $session, User $user): bool
    {
        $stored = $session->get('password_hash_web');
        if ($stored === null) {
            return true;
        }
        $current = (string) $user->getAuthPassword();

        return is_string($stored) && (hash_equals($guard->hashPasswordForCookie($current), $stored) || hash_equals($current, $stored));
    }
}
