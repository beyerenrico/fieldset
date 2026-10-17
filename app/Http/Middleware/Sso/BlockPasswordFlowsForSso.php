<?php

declare(strict_types=1);

namespace App\Http\Middleware\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use App\Domain\Identity\Sso\Login\DomainProviderLookup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every Fortify route (config/fortify.php `middleware`; ADR 0036, Enforcement): the forgot-password
 * and reset-password requests for an email whose domain enforces SSO are refused (422 `sso_required`,
 * `auth.password_reset_blocked`) before Fortify sends a link or checks a token — including a token issued
 * before enforcement was switched on. The login is gated in Fortify's authenticateUsing callback.
 *
 * The valid break-glass Owner may still reset: Fortify sends the link, but the answer is the same 422
 * `sso_required` every other address of the domain gets, so forgot-password cannot reveal which address is
 * the break-glass account.
 */
final readonly class BlockPasswordFlowsForSso
{
    private const array RESET_ROUTES = ['password.email' => 'forgot', 'password.update' => 'reset'];

    public function __construct(private PasswordAuthGate $gate, private BlockedPasswordFlow $blocked, private DomainProviderLookup $lookup) {}

    public function handle(Request $request, Closure $next): Response
    {
        $via = self::RESET_ROUTES[(string) $request->route()?->getName()] ?? null;
        $email = $request->input('email');
        if ($via === null || ! is_string($email) || trim($email) === '') {
            return $next($request);
        }

        try {
            $this->gate->assertPasswordReset($email);
        } catch (SsoRequiredException $e) {
            throw $this->blocked->reset($email, $e, $via);
        }

        if ($via === 'forgot' && $this->lookup->enforcingProviderFor($email) instanceof IdentityProvider) {
            $next($request); // the break-glass Owner: Fortify sends the link

            throw BlockedPasswordFlow::exception();
        }

        return $next($request);
    }
}
