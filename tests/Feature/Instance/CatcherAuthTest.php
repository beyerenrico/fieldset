<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Instance\Runtime\CatcherRoute;
use App\Domain\Platform\Auth\PlatformSession;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Instance\RuntimeFixture;

require_once __DIR__.'/Support.php';
require_once __DIR__.'/../Platform/Support.php';

/*
 * Plan U8 / R66 (ADR 0075): in domain mode the edge's forward_auth asks /_fsa/catcher-auth before every /_mail/
 * request. Only an active operator whose session signed in on /platform and who has MFA passes; every session's
 * first opening is audited once. The session is read from the cookie and never written back: Mailpit fires many
 * parallel requests, and a write would race with the /platform tab. Sessions live in the database here, so
 * "never saved" is checked against the stored row.
 */

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    catcherForgetSessionStores();
});

afterEach(function (): void {
    RuntimeFixture::cleanup();
    Request::setTrustedHosts([]);
});

function catcherForgetSessionStores(): void
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/**
 * A stored session as /platform/login leaves it: the web guard's user id, the platform mark and the password hash
 * AuthenticateSession keeps. Returns the session id.
 *
 * @param  array<string, mixed>  $changes  attributes to replace (null removes one)
 */
function catcherSession(User $user, array $changes = []): string
{
    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);
    $store = app('session')->driver();
    $store->setId(null);
    $store->start();
    $attributes = [
        $guard->getName() => $user->id,
        PlatformSession::KEY => ['user_id' => $user->id, 'at' => now()->getTimestamp()],
        'password_hash_web' => $guard->hashPasswordForCookie((string) $user->getAuthPassword()),
        ...$changes,
    ];
    foreach ($attributes as $key => $value) {
        $value === null ? $store->forget($key) : $store->put($key, $value);
    }
    $store->save();
    catcherForgetSessionStores();

    return $store->getId();
}

/**
 * One forward_auth subrequest as Caddy sends it (a GET with the browser's headers), with the session cookie
 * encrypted like a browser would carry it.
 *
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function catcherAuth(?string $sessionId, array $headers = ['Sec-Fetch-Mode' => 'navigate', 'Accept' => 'text/html'], string $method = 'GET'): TestResponse
{
    $test = test();
    $name = (string) config('session.cookie');
    // Encrypted with the cookie-name prefix, as EncryptCookies expects (what the test client's withCookie() does).
    $cookies = $sessionId === null ? [] : [$name => encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$sessionId, false)];
    $server = (fn (array $headers): array => $this->transformHeadersToServerVars($headers))->call($test, [...$headers, 'X-Forwarded-Uri' => '/_mail/']);
    $response = $test->call($method, CatcherRoute::AUTH_PATH, [], $cookies, [], $server);
    catcherForgetSessionStores();

    return $response;
}

function catcherOpenings(): int
{
    return Activity::query()->where('event', 'platform.catcher_opened')->count();
}

/** @return array{payload: string, last_activity: int}|null */
function catcherStoredSession(string $id): ?array
{
    $row = DB::table('sessions')->where('id', $id)->first(['payload', 'last_activity']);

    return $row === null ? null : ['payload' => (string) $row->payload, 'last_activity' => (int) $row->last_activity];
}

it('serves the catcher auth path that the edge calls', function (): void {
    expect(route('instance.catcher-auth', absolute: false))->toBe(CatcherRoute::AUTH_PATH);
});

it('lets an operator with MFA and a /platform session through and audits the first opening of the session once', function (): void {
    $operator = platformOperator();
    $session = catcherSession($operator);

    catcherAuth($session)->assertOk()->assertSeeText('ok')->assertHeader('Cache-Control', 'no-store, private');
    catcherAuth($session, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();

    $entry = Activity::query()->where('event', 'platform.catcher_opened')->sole();
    expect(catcherOpenings())->toBe(1)
        ->and((string) $entry->causer_id)->toBe((string) $operator->id)
        ->and((string) $entry->subject_id)->toBe((string) $operator->id)
        ->and($entry->properties->get('email'))->toBe($operator->email);

    catcherAuth(catcherSession($operator))->assertOk();
    expect(catcherOpenings())->toBe(2);
});

it('answers 401 to fetches, XHR and websockets without access', function (array $headers): void {
    catcherAuth(null, $headers)->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'XHR' => [['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest']],
    'fetch' => [['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'cors']],
    'websocket' => [['Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==']],
    'JSON' => [['Accept' => 'application/json']],
    'Accept */* without Sec-Fetch' => [['Accept' => '*/*']], // Symfony's test requests default to an HTML Accept
]);

it('redirects a browser navigation without access to the /platform sign-in', function (array $headers): void {
    $response = catcherAuth(null, $headers);

    $response->assertRedirect()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->get('Location'))->toEndWith('/platform/login');
})->with([
    'Sec-Fetch-Mode' => [['Sec-Fetch-Mode' => 'navigate', 'Accept' => '*/*']],
    'older browser' => [['Accept' => 'text/html,application/xhtml+xml']],
]);

it('refuses sessions that are not an operator /platform sign-in with MFA', function (string $case): void {
    $operator = platformOperator();
    $session = match ($case) {
        'admin session' => catcherSession(memberOf(makeOrganization(), SystemRole::Admin), [PlatformSession::KEY => null]),
        'non-operator with a mark' => catcherSession(memberOf(makeOrganization(), SystemRole::Admin)),
        'operator without the mark' => catcherSession($operator, [PlatformSession::KEY => null]),
        'operator without MFA' => catcherSession(tap($operator, fn (User $user): bool => $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save())),
        'deactivated operator' => catcherSession(tap($operator, fn (User $user): bool => $user->forceFill(['status' => 'deactivated'])->save())),
        'mark of another user' => catcherSession(memberOf(makeOrganization(), SystemRole::Admin), [PlatformSession::KEY => ['user_id' => $operator->id, 'at' => now()->getTimestamp()]]),
        'password changed elsewhere' => catcherSession($operator, ['password_hash_web' => 'stale-hash']),
        'unknown user' => catcherSession($operator, [Auth::guard('web')->getName() => 999999]),
    };

    catcherAuth($session)->assertRedirect();
    catcherAuth($session, ['X-Requested-With' => 'XMLHttpRequest'])->assertUnauthorized();
    expect(catcherOpenings())->toBe(0);
})->with([
    'admin session', 'non-operator with a mark', 'operator without the mark', 'operator without MFA', 'deactivated operator',
    'mark of another user', 'password changed elsewhere', 'unknown user',
]);

it('closes when the /platform sign-in is older than platform.session_hours', function (): void {
    config(['session.lifetime' => 60 * 48]); // the stored session itself outlives the mark
    $session = catcherSession(platformOperator());
    catcherAuth($session)->assertOk();

    $this->travel((int) config('platform.session_hours', 12) + 1)->hours();

    catcherAuth($session, ['X-Requested-With' => 'XMLHttpRequest'])->assertUnauthorized();
});

it('accepts the raw password hash older sessions stored', function (): void {
    $operator = platformOperator();

    catcherAuth(catcherSession($operator, ['password_hash_web' => (string) $operator->getAuthPassword()]))->assertOk();
});

it('never writes the session or sets a cookie', function (string $case): void {
    $operator = platformOperator();
    $session = catcherSession($operator, $case === 'denied' ? [PlatformSession::KEY => null] : []);
    $before = catcherStoredSession($session);
    $rows = DB::table('sessions')->count();
    $this->travel(5)->minutes();

    $response = catcherAuth($session);

    expect($response->status())->toBe($case === 'denied' ? 302 : 200)
        ->and($response->headers->getCookies())->toBe([])
        ->and(catcherStoredSession($session))->toBe($before)
        ->and(DB::table('sessions')->count())->toBe($rows);
})->with(['allowed', 'denied']);

it('starts no session for a request without a cookie', function (): void {
    $rows = DB::table('sessions')->count();

    $response = catcherAuth(null);

    expect($response->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count())->toBe($rows);
});

it('answers only GET', function (): void {
    $session = catcherSession(platformOperator());

    catcherAuth($session, [], 'POST')->assertStatus(405);
});

it('is not reachable through the tunnel', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel());

    runtimeRequest('http://localhost:8080'.CatcherRoute::AUTH_PATH, RuntimeFixture::PEER, ['Sec-Fetch-Mode' => 'navigate'])->assertNotFound();
});
