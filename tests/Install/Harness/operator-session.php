<?php

declare(strict_types=1);

/*
 * J2 helper (plan U8, R66; ADR 0075), copied into a running instance's app container and run there with `php`:
 * leaves the session a /platform sign-in with password and MFA leaves behind, for the operator given as the first
 * argument (MFA is enrolled first when missing), and prints the session cookie for an HTTPS host as JSON
 * `{"name": "__Host-…", "value": "…"}`. Driving Filament's Livewire login and TOTP challenge over plain HTTP would
 * test Filament rather than the edge; the catcher auth endpoint reads exactly what that sign-in writes.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Auth\PlatformSession;
use App\Http\Middleware\Security\SecureCookies;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->where('email', strtolower((string) ($argv[1] ?? '')))->firstOrFail();
if ($user->getAppAuthenticationSecret() === null) {
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
}

$name = SecureCookies::HOST_PREFIX.config('session.cookie');
config(['session.cookie' => $name]);
$session = app('session')->driver();
$session->start();
$session->put(Auth::guard('web')->getName(), $user->getAuthIdentifier());
app(PlatformSession::class)->mark($user);
$session->save();

echo json_encode(['name' => $name, 'value' => Crypt::encrypt(CookieValuePrefix::create($name, Crypt::getKey()).$session->getId(), false)]), "\n";
