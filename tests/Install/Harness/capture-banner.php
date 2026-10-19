<?php

declare(strict_types=1);

/*
 * J4 helper (plan U12, AE1/R59; ADR 0080), copied into a running local instance's app container and run there with
 * `php`. `setup <owner-email>` prepares the two organizations of AE1: the installed one (A, on the instance mailer)
 * gains an admin, a new one (B) gets its own SMTP server in its mail settings and an admin; an invitation to A goes
 * out through the normal flow (queued, sent by the queue worker through the instance mailer). It prints JSON with
 * both slugs and session cookies for the operator (A's owner), A's admin and B's admin, as a password sign-in leaves
 * them: the banner is server-rendered, so plain HTTP requests with these cookies see what a browser sees.
 * `invite <email>` sends one more invitation to A.
 */

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailSetting;
use App\Http\Middleware\Security\SecureCookies;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenants = app(TenantContext::class);
$owner = User::query()->where('email', strtolower((string) ($argv[2] ?? 'owner@example.test')))->firstOrFail();
$organizationA = $owner->organizations()->orderBy('organizations.id')->firstOrFail();

/** A verified account with a known password (never used: the session below stands for its sign-in). */
function captureAccount(string $email): User
{
    $user = User::query()->firstOrCreate(['email' => $email], ['name' => Str::before($email, '@'), 'password' => Str::password(32), 'locale' => 'en']);
    $user->markEmailAsVerified();

    return $user;
}

/** @return array{name: string, value: string} the session cookie of a signed-in user (plain HTTP: no __Host- prefix) */
function captureSession(User $user): array
{
    $name = (string) preg_replace('/^'.preg_quote(SecureCookies::HOST_PREFIX, '/').'/', '', (string) config('session.cookie'));
    config(['session.cookie' => $name]);
    app('session')->forgetDrivers(); // a fresh store and handler: the database handler would only UPDATE a second id
    $session = app('session')->driver();
    $session->setId(Str::random(40));
    $session->flush();
    $session->put(Auth::guard('web')->getName(), $user->getAuthIdentifier());
    $session->save();

    return ['name' => $name, 'value' => Crypt::encrypt(CookieValuePrefix::create($name, Crypt::getKey()).$session->getId(), false)];
}

if (($argv[1] ?? '') === 'invite') {
    $email = strtolower($argv[3] ?? 'second-invitee@example.test');
    $tenants->run($organizationA, fn () => app(InviteMember::class)->handle($owner, $organizationA, $email, 'Second Invitee', [SystemRole::FieldWorker]));
    echo json_encode(['invited' => $email]), "\n";

    exit(0);
}

$adminA = captureAccount('a-admin@example.test');
$tenants->run($organizationA, fn () => $adminA->organizations()->whereKey($organizationA->id)->exists() ?: app(AddMember::class)->handle($organizationA, $adminA, [SystemRole::Admin]));
$adminB = captureAccount('b-admin@example.test');
$organizationB = $adminB->organizations()->first() ?? app(CreateOrganization::class)->handle(['name' => 'Own SMTP Org', 'locale' => 'en'], $adminB);
$tenants->run($organizationB, fn () => MailSetting::query()->updateOrCreate([], [
    'smtp_enabled' => true, 'smtp_host' => 'smtp.own-smtp.test', 'smtp_port' => 587, 'smtp_from_address' => 'forms@own-smtp.test',
]));
$tenants->run($organizationA, fn () => app(InviteMember::class)->handle($owner, $organizationA, 'invitee@example.test', 'Ina Invitee', [SystemRole::FieldWorker]));

echo json_encode([
    'org_a' => $organizationA->slug,
    'org_b' => $organizationB->slug,
    'cookies' => ['operator' => captureSession($owner), 'admin_a' => captureSession($adminA), 'admin_b' => captureSession($adminB)],
]), "\n";
