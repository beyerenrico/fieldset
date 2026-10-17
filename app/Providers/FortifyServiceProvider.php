<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

/**
 * Fortify serves the Field App's headless auth endpoints under /auth (BUILD_PROMPT §5, §9.3).
 * The admin panel uses Filament's own login, sharing the same TOTP secret (ADR 0016).
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Enforced SSO answers 422 `sso_required` for every address of the domain (ADR 0036); only the valid
        // break-glass Owner with the correct password passes, into the MFA challenge — a wrong break-glass
        // password gets the same answer, so the account cannot be told apart. Deactivated users cannot log in (§9.3).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower((string) $request->input('email'));
            try {
                app(PasswordAuthGate::class)->assertPasswordLoginWith($email, (string) $request->input('password'));
            } catch (SsoRequiredException $e) {
                throw app(BlockedPasswordFlow::class)->login($email, $e, 'fortify');
            }

            $user = User::query()->where('email', $email)->first();

            return $user !== null && $user->isActive() && Hash::check((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });

        // Password reset links open the Field App (Fortify runs without views).
        ResetPassword::createUrlUsing(static fn (User $user, string $token): string => url('/app/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ])));

        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            // Throttled attempts are audited (Lockout -> auth.lockout once per window, §8.11); the 429 stays Laravel's.
            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers): never {
                event(new Lockout($request));

                throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
            });
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));
    }
}
