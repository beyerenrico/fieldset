<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle and absolute timeouts of the admin panel (ASVS 3.3.2): the shared session lives for SESSION_LIFETIME
 * (30 days, for the offline Field App), so /admin ends its own use of it after
 * `security.admin_session.idle_minutes` without an admin request (default 30) or
 * `security.admin_session.absolute_hours` after the first admin request of the sign-in (default 12).
 * - Registered as persistent auth middleware of the admin panel only, so Livewire updates of admin pages count
 *   as activity, while the Field App (/app, /auth, /api) and /platform never pass through it.
 * - On timeout the web guard signs out (which also forgets the remember-me cookie and cycles its token), the
 *   session is invalidated and the browser goes to /admin/login with a notice.
 * - Every sign-in (Login event) and every impersonation switch (SessionLogin) restarts both timers: the
 *   timestamps belong to the user who signed in, never to an earlier user of the session.
 */
final readonly class AdminSessionTimeout
{
    public const string STARTED_AT = 'auth.admin_started_at';

    public const string LAST_ACTIVITY = 'auth.admin_last_activity_at';

    public const array KEYS = [self::STARTED_AT, self::LAST_ACTIVITY];

    public function __construct(private AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $this->auth->guard('web')->check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = CarbonImmutable::now()->getTimestamp();
        $startedAt = $session->get(self::STARTED_AT);
        $lastActivity = $session->get(self::LAST_ACTIVITY);
        if ($this->timedOut($startedAt, $lastActivity, $now)) {
            return $this->expire($session);
        }

        $session->put(self::STARTED_AT, is_int($startedAt) ? $startedAt : $now);
        $session->put(self::LAST_ACTIVITY, $now);

        return $next($request);
    }

    /** Listener for Login: a new sign-in starts with fresh timers (set on its first admin request). */
    public function restart(Login $event): void
    {
        if ($event->guard === 'web') {
            app(Session::class)->forget(self::KEYS);
        }
    }

    public static function idleSeconds(): int
    {
        return max(1, (int) config('security.admin_session.idle_minutes', 30)) * 60;
    }

    public static function absoluteSeconds(): int
    {
        return max(1, (int) config('security.admin_session.absolute_hours', 12)) * 3600;
    }

    private function timedOut(mixed $startedAt, mixed $lastActivity, int $now): bool
    {
        return (is_int($lastActivity) && $now - $lastActivity >= self::idleSeconds())
            || (is_int($startedAt) && $now - $startedAt >= self::absoluteSeconds());
    }

    private function expire(Session $session): Response
    {
        $this->auth->guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();
        Notification::make()->warning()->title(__('errors.session_expired'))->persistent()->send();

        return redirect()->to(Filament::getPanel('admin')->getLoginUrl() ?? url('/admin/login'));
    }
}
