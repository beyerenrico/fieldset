<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

/* Security headers per surface (BUILD_PROMPT §11, ADR 0040): CSP, framing, nosniff, referrer, permissions. */

/** The nonce of the response's CSP (script-src 'nonce-…'), or null. */
function cspNonce(TestResponse $response): ?string
{
    return preg_match("/'nonce-([A-Za-z0-9+\\/=_-]+)'/", (string) $response->headers->get('Content-Security-Policy'), $m) === 1 ? $m[1] : null;
}

/** @return array<string, string> directive => sources of the response's CSP */
function cspDirectives(TestResponse $response): array
{
    $directives = [];
    foreach (explode(';', (string) $response->headers->get('Content-Security-Policy')) as $part) {
        $words = preg_split('/\s+/', trim($part)) ?: [];
        if (($words[0] ?? '') !== '') {
            $directives[array_shift($words)] = implode(' ', $words);
        }
    }

    return $directives;
}

/** Executable inline scripts (no src, JavaScript type) whose nonce attribute is not the given nonce. */
function inlineScriptsWithoutNonce(string $html, string $nonce): array
{
    preg_match_all('/<script\b([^>]*)>/i', $html, $tags);

    return array_values(array_filter($tags[1], function (string $attributes) use ($nonce): bool {
        if (preg_match('/\ssrc\s*=/i', $attributes) === 1) {
            return false;
        }
        if (preg_match('/\stype\s*=\s*["\']?([^"\'\s>]+)/i', $attributes, $type) === 1
            && ! in_array(strtolower($type[1]), ['module', 'text/javascript', 'application/javascript'], true)) {
            return false;
        }

        return preg_match('/\snonce\s*=\s*"'.preg_quote($nonce, '/').'"/', $attributes) !== 1;
    }));
}

function withSpaShell(string $relative, Closure $test): void
{
    $path = public_path($relative);
    $existed = is_file($path);
    if (! $existed) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<!doctype html><div id="root"></div>');
    }
    try {
        $test();
    } finally {
        if (! $existed) {
            File::delete($path);
        }
    }
}

it('sends a nonce-based CSP on the admin login page and puts the nonce on every inline script', function (): void {
    $response = $this->get('/admin/login')->assertOk();
    $csp = cspDirectives($response);
    $nonce = cspNonce($response);

    expect($nonce)->not->toBeNull()
        ->and($csp['script-src'])->toBe("'self' 'nonce-{$nonce}' 'unsafe-eval'")
        ->and($csp['script-src'])->not->toContain('unsafe-inline')
        ->and($csp['style-src'])->toBe("'self' 'unsafe-inline'")
        ->and($csp['img-src'])->toBe("'self' data: blob:")
        ->and($csp['default-src'])->toBe("'self'")
        ->and($csp['object-src'])->toBe("'none'")
        ->and($csp['base-uri'])->toBe("'self'")
        ->and($csp['form-action'])->toBe("'self'")
        ->and($csp['frame-ancestors'])->toBe("'none'")
        ->and($csp)->not->toHaveKey('upgrade-insecure-requests')
        ->and(inlineScriptsWithoutNonce((string) $response->getContent(), (string) $nonce))->toBe([])
        ->and($response->getContent())->toContain('nonce="'.$nonce.'"');
});

it('changes the nonce on every request', function (): void {
    expect(cspNonce($this->get('/admin/login')))->not->toBe(cspNonce($this->get('/admin/login')));
});

it('sends the common headers on admin pages', function (): void {
    $org = makeOrganization();

    $response = $this->actingAs(ownerOf($org))->get("/admin/{$org->slug}")->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('geolocation=()')->toContain('microphone=()')
        ->and(inlineScriptsWithoutNonce((string) $response->getContent(), (string) cspNonce($response)))->toBe([]);
});

it('keeps the strict static CSP of the Field App and allows camera and GPS there only', function (): void {
    withSpaShell('app/index.html', function (): void {
        $response = $this->get('/app/forms/1')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

        expect($response->headers->get('Content-Security-Policy'))->toBe(implode('; ', [
            "default-src 'self'", "script-src 'self'", "style-src 'self' 'unsafe-inline'", "img-src 'self' data: blob:",
            "font-src 'self' data:", "connect-src 'self'", "worker-src 'self'", "manifest-src 'self'", "object-src 'none'",
            "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
        ]))->and($response->headers->get('Permissions-Policy'))->toContain('camera=(self)')->toContain('geolocation=(self)')->toContain('microphone=()');
    });
});

it('sends a no-content CSP and CORP on API responses', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Cross-Origin-Opener-Policy');
});

it('keeps a CSP the controller set itself', function (): void {
    Route::get('/csp-probe', fn () => response('x', 200, ['Content-Security-Policy' => "default-src 'none'; sandbox"]))->middleware('web');

    $this->get('/csp-probe')->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
});

it('gives third-party UIs (API docs, /up) only the baseline policy', function (): void {
    $docs = $this->get('/docs/api');
    $up = $this->get('/up')->assertOk();

    foreach ([$docs, $up] as $response) {
        expect($response->headers->get('Content-Security-Policy'))->toBe("object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
});

it('puts the nonce on Horizon\'s inlined bundle', function (): void {
    config(['app.platform_admins' => ['ops@example.com']]);
    $ops = User::factory()->create(['email' => 'ops@example.com']);

    $response = $this->actingAs($ops)->get('/horizon')->assertOk();
    $nonce = (string) cspNonce($response);

    expect(cspDirectives($response)['script-src'])->toBe("'self' 'nonce-{$nonce}' 'unsafe-eval'")
        ->and(cspDirectives($response)['style-src'])->toBe("'self' 'unsafe-inline' https://fonts.bunny.net")
        ->and(inlineScriptsWithoutNonce((string) $response->getContent(), $nonce))->toBe([]);
});

it('allows the Vite dev server origin while it runs', function (): void {
    $hot = storage_path('framework/testing/hot');
    File::ensureDirectoryExists(dirname($hot));
    File::put($hot, 'http://[::1]:5173');
    Vite::useHotFile($hot);

    try {
        $csp = cspDirectives($this->get('/admin/login'));
    } finally {
        File::delete($hot);
    }

    expect($csp['script-src'])->toEndWith("'unsafe-eval' http://[::1]:5173")
        ->and($csp['connect-src'])->toBe("'self' http://[::1]:5173 ws://[::1]:5173");
});
