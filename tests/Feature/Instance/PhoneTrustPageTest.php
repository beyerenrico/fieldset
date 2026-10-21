<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Runtime\LocalCaRoot;
use App\Domain\Instance\Runtime\PhoneTrustRoute;
use App\Domain\Instance\Tunnel\TunnelRules;
use Illuminate\Auth\Events\Login;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Instance\LocalCaFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\RuntimeFixture;

require_once __DIR__.'/Support.php';

/*
 * Plan U14 / R19 (ADR 0057, ADR 0082): in local-CA mode the edge serves the phone trust page and the CA's root on
 * plain HTTP (port 80) and HTTPS, as a trusted proxy with X-Forwarded-Proto. No sign-in, no session, no cookie; over
 * plain HTTP no HSTS and no upgrade-insecure-requests, so a phone that does not trust the CA yet can use the page.
 * Outside local-CA mode, and through the tunnel, both are 404.
 */

const PHONE_TRUST_EDGE = '10.213.0.2';
const PHONE_TRUST_URL = 'https://192.168.1.20';

beforeEach(function (): void {
    $this->runtime = RuntimeFixture::install(['instance_url' => PHONE_TRUST_URL, 'local_url' => 'http://localhost:8080']);
    $this->pem = LocalCaFixture::root();
    LocalCaFixture::install($this->runtime, $this->pem);
    config(['trustedproxy.proxies' => PHONE_TRUST_EDGE]);
});

afterEach(function (): void {
    RuntimeFixture::cleanup();
    Request::setTrustedHosts([]);
});

/**
 * One request through the edge for the LAN address. Accept-Language is empty unless given (Symfony's test requests
 * default to English).
 *
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $cookies  already encrypted, as a browser carries them
 * @return TestResponse<Response>
 */
function phoneTrust(string $path = PhoneTrustRoute::PATH, string $proto = 'http', array $headers = [], array $cookies = []): TestResponse
{
    $test = test();
    $server = (fn (array $headers): array => $this->transformHeadersToServerVars($headers))
        ->call($test, ['X-Forwarded-Proto' => $proto, 'Accept-Language' => '', ...$headers]);

    return $test->call('GET', 'http://192.168.1.20'.$path, [], $cookies, [], [...$server, 'REMOTE_ADDR' => PHONE_TRUST_EDGE]);
}

/** @param  TestResponse<Response>  $response */
function phoneTrustText(TestResponse $response): string
{
    return html_entity_decode(strip_tags((string) $response->getContent()), ENT_QUOTES | ENT_HTML5);
}

it('serves the two paths that the edge forwards from plain HTTP', function (): void {
    expect(route('instance.phone-trust', absolute: false))->toBe(PhoneTrustRoute::PATH)
        ->and(route('instance.phone-trust.root', absolute: false))->toBe(PhoneTrustRoute::ROOT_PATH)
        ->and(LocalCaRoot::FILE)->toBe('local-ca-root.crt');
});

it('serves the page over plain HTTP without sign-in, session or cookie', function (): void {
    $response = phoneTrust()->assertOk();

    expect($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->getCookies())->toBe([])
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('shows the root fingerprint exactly as openssl prints it, and the root name', function (): void {
    $fingerprint = LocalCaFixture::fingerprint($this->pem);
    $response = phoneTrust()->assertOk();

    expect(phoneTrustText($response))->toContain($fingerprint)->toContain(LocalCaFixture::ROOT_NAME)
        ->and((string) $response->getContent())->toContain('data-fingerprint="'.$fingerprint.'"')
        ->and($fingerprint)->toBe(implode(':', str_split(strtoupper((string) openssl_x509_fingerprint($this->pem, 'sha256')), 2)));
});

it('explains the iOS and Android steps, the tunnel on Android, the unsynced data and the removal (DE by default)', function (): void {
    $response = phoneTrust()->assertOk()->assertSee('<html lang="de">', false);
    $text = phoneTrustText($response);

    expect($text)->toContain('https://192.168.1.20')->toContain('https://192.168.1.20/app/')
        ->toContain('Einstellungen → Allgemein → VPN und Geräteverwaltung')
        ->toContain('Zertifikatsvertrauenseinstellungen und aktivieren Sie das volle Vertrauen für „'.LocalCaFixture::ROOT_NAME.'“')
        ->toContain('„Zertifikat installieren“ → „CA-Zertifikat“')
        ->toContain('fieldset tunnel start')->toContain('noch nicht synchronisiert')->toContain('Profil entfernen')
        ->toContain('für jede beliebige Website bürgen')->toContain('fieldset status');
    expect(substr_count((string) $response->getContent(), '<ol data-steps='))->toBe(2);
});

it('tells Android users to turn the local CA off before the tunnel, which refuses to start next to it (R61)', function (string $lang): void {
    $hint = '';
    try {
        TunnelRules::assertCanStart(PhoneFixtures::localCa());
    } catch (InvalidInput $refusal) {
        $hint = (string) $refusal->hint;
    }
    preg_match('/`(fieldset [^`]+)`/', $hint, $command);
    $text = phoneTrustText(phoneTrust(PhoneTrustRoute::PATH.'?lang='.$lang)->assertOk());

    expect($command[1] ?? null)->toBe('fieldset config set phone-https off --yes')
        ->and($text)->toContain($command[1])->toContain('fieldset tunnel start')
        ->and(strpos($text, $command[1]))->toBeLessThan(strpos($text, 'fieldset tunnel start'));
})->with(['de', 'en']);

it('speaks English with ?lang=en or an English browser, German otherwise', function (array $query, array $headers, string $lang): void {
    $response = phoneTrust(PhoneTrustRoute::PATH.$query[0], 'http', $headers)->assertOk()->assertSee('<html lang="'.$lang.'">', false);

    expect(phoneTrustText($response))->toContain($lang === 'en' ? 'Certificate Trust Settings' : 'Zertifikatsvertrauenseinstellungen');
})->with([
    '?lang=en' => [['?lang=en'], [], 'en'],
    'Accept-Language en-US' => [[''], ['Accept-Language' => 'en-US,en;q=0.8'], 'en'],
    '?lang=de over an English browser' => [['?lang=de'], ['Accept-Language' => 'en'], 'de'],
    'Accept-Language fr' => [[''], ['Accept-Language' => 'fr-FR'], 'de'],
    'unknown ?lang' => [['?lang=xx'], [], 'de'],
]);

it('renders the Field App URL as an inline SVG QR code with a text alternative', function (): void {
    $html = (string) phoneTrust()->assertOk()->getContent();

    expect($html)->toContain('role="img" aria-label="QR-Code für https://192.168.1.20/app/"')
        ->toMatch('/<svg aria-hidden="true" focusable="false" xmlns="http:\/\/www\.w3\.org\/2000\/svg"[^>]*viewBox="0 0 232 232"/')
        ->toContain('<figcaption><code>https://192.168.1.20/app/</code></figcaption>')
        ->not->toContain('<?xml');
});

it('sends neither HSTS nor upgrade-insecure-requests over plain HTTP, both over HTTPS', function (string $path): void {
    $plain = phoneTrust($path)->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    expect((string) $plain->headers->get('Content-Security-Policy'))->not->toContain('upgrade-insecure-requests');

    $secure = phoneTrust($path, 'https')->assertOk()->assertHeader('Strict-Transport-Security');
    expect((string) $secure->headers->get('Content-Security-Policy'))->toContain('upgrade-insecure-requests');
})->with([PhoneTrustRoute::PATH, PhoneTrustRoute::ROOT_PATH]);

it('keeps HSTS for every other page of the instance host over plain HTTP', function (): void {
    phoneTrust('/login-link')->assertOk()->assertHeader('Strict-Transport-Security');
});

it('offers exactly the public root PEM for download, never a key', function (): void {
    $response = phoneTrust(PhoneTrustRoute::ROOT_PATH)->assertOk()
        ->assertHeader('Content-Type', 'application/x-x509-ca-cert')
        ->assertHeader('Content-Disposition', 'attachment; filename="form-schema-app-local-ca.crt"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe($this->pem)->not->toContain('PRIVATE KEY')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([]);
});

it('links the certificate relatively and loads nothing from elsewhere', function (): void {
    $html = (string) phoneTrust()->assertOk()->getContent();
    preg_match_all('/\b(?:src|href|action)="([^"]*)"/', $html, $matches);

    expect($html)->toContain('<meta name="viewport" content="width=device-width, initial-scale=1">')
        ->toContain('href="'.PhoneTrustRoute::ROOT_PATH.'"')
        ->not->toContain('<link ')->not->toMatch('/<script[^>]+src=/')
        ->and($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $url) {
        expect(str_starts_with($url, '/') && ! str_starts_with($url, '//') || str_starts_with($url, '?') || str_starts_with($url, PHONE_TRUST_URL.'/'))
            ->toBeTrue("unexpected link {$url}");
    }
});

it('runs the trust check against the instance HTTPS address with a nonce, only on the instance host', function (): void {
    $response = phoneTrust()->assertOk();
    $html = (string) $response->getContent();
    $policy = (string) $response->headers->get('Content-Security-Policy');
    expect($html)->toContain('data-probe="https://192.168.1.20/app/icons/icon-192.png"')->toContain('role="status" aria-live="polite"')
        ->toMatch('/<script\s+nonce="[^"]+"\s*>/')->toContain('<noscript>')
        ->and(preg_match('/<script\s+nonce="([^"]+)"/', $html, $nonce))->toBe(1)
        ->and($policy)->toContain("img-src 'self' data: blob: https://192.168.1.20;")
        ->toContain("script-src 'self' 'nonce-{$nonce[1]}'")->toContain("default-src 'self'");

    $local = (string) runtimeRequest('http://localhost:8080'.PhoneTrustRoute::PATH, '127.0.0.1', ['Accept-Language' => ''])->assertOk()->getContent();
    expect($local)->not->toContain('<script')->toContain('http://192.168.1.20/phone-trust')->toContain('href="https://192.168.1.20/app/"');
});

it('answers 404 outside local-CA mode or without an acceptable root', function (Closure $arrange): void {
    $arrange->call($this);

    foreach ([PhoneTrustRoute::PATH, PhoneTrustRoute::ROOT_PATH] as $path) {
        $response = runtimeRequest('http://192.168.1.20'.$path, PHONE_TRUST_EDGE, ['X-Forwarded-Proto' => 'http'])->assertNotFound();
        expect((string) $response->getContent())->not->toContain('BEGIN CERTIFICATE')
            ->and($response->headers->getCookies())->toBe([]);
    }
})->with([
    'no runtime configuration' => [function (): void {
        config(['instance.runtime_path' => null]);
    }],
    'no root file' => [function (): void {
        unlink($this->runtime.'/'.LocalCaRoot::FILE);
    }],
    'an invalid root file' => [function (): void {
        LocalCaFixture::install($this->runtime, "-----BEGIN CERTIFICATE-----\nbm90IGEgY2VydA==\n-----END CERTIFICATE-----\n");
    }],
    'a root file that also holds a key' => [function (): void {
        LocalCaFixture::install($this->runtime, $this->pem.LocalCaFixture::privateKey());
    }],
    'a certificate that is not a CA' => [function (): void {
        LocalCaFixture::install($this->runtime, LocalCaFixture::leaf());
    }],
    'local mode' => [function (): void {
        RuntimeFixture::write($this->runtime, RuntimeFixture::document());
    }],
    'domain mode' => [function (): void {
        RuntimeFixture::write($this->runtime, RuntimeFixture::document(RuntimeFixture::domain('192.168.1.20.nip.io')));
    }],
    'an https IP URL with a port' => [function (): void {
        RuntimeFixture::write($this->runtime, RuntimeFixture::document(['instance_url' => 'https://192.168.1.20:8443']));
    }],
]);

it('is not reachable through the tunnel', function (): void {
    RuntimeFixture::write($this->runtime, RuntimeFixture::document(['instance_url' => PHONE_TRUST_URL, ...RuntimeFixture::tunnel()]));

    foreach ([PhoneTrustRoute::PATH, PhoneTrustRoute::ROOT_PATH] as $path) {
        runtimeRequest('http://192.168.1.20'.$path, RuntimeFixture::PEER)->assertNotFound();
        runtimeRequest('https://'.RuntimeFixture::TUNNEL.$path, PHONE_TRUST_EDGE, ['X-Forwarded-Proto' => 'https'])->assertNotFound();
    }
});

it('never signs anyone in from a remember-me cookie', function (): void {
    config(['session.driver' => 'database']);
    Route::middleware('web')->get('_probe/phone-trust-whoami', fn (): string => (string) Auth::id());
    Route::getRoutes()->refreshNameLookups();
    $user = User::factory()->create();
    $user->forceFill(['remember_token' => str_repeat('r', 60)])->saveQuietly();
    $name = Auth::guard('web')->getRecallerName();
    $value = $user->id.'|'.$user->getRememberToken().'|'.$user->getAuthPassword();
    $cookies = [$name => encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false)];
    Event::fake([Login::class]);
    $sessions = DB::table('sessions')->count();

    $response = phoneTrust(PhoneTrustRoute::PATH, 'http', [], $cookies)->assertOk()->assertDontSee($user->email);
    phoneTrust(PhoneTrustRoute::ROOT_PATH, 'http', [], $cookies)->assertOk();

    Event::assertNotDispatched(Login::class);
    expect(DB::table('sessions')->count())->toBe($sessions)
        ->and($response->headers->getCookies())->toBe([])
        ->and(Auth::guard('web')->hasUser())->toBeFalse();

    // The same cookie does sign in on a page with the session stack, so the check above is meaningful.
    phoneTrust('/_probe/phone-trust-whoami', 'http', [], $cookies)->assertOk()->assertSee((string) $user->id);
    Event::assertDispatched(Login::class);
});
