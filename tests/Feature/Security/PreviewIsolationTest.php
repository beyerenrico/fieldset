<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\File;

/* Builder preview on an opaque origin (QUESTIONS #10, ADR 0040): sandbox, CSP, CORS, postMessage contract. */

beforeEach(function (): void {
    $this->withoutVite();
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    $this->form = app(TenantContext::class)->run($this->org, fn (): Form => app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report'));
});

/** @return list<string> the sandbox attribute of every iframe in the HTML */
function iframeSandboxes(string $html): array
{
    preg_match_all('/<iframe\b[^>]*\bsandbox="([^"]*)"/i', $html, $matches);

    return $matches[1];
}

it('sandboxes the preview document itself and cuts it off from the network', function (): void {
    $path = public_path('form-preview/preview.html');
    $existed = is_file($path);
    if (! $existed) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<!doctype html>preview');
    }

    try {
        $response = $this->get('/form-preview/')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeaderMissing('Cross-Origin-Opener-Policy');
    } finally {
        if (! $existed) {
            File::delete($path);
        }
    }

    $self = "'self' ".rtrim(url('/'), '/');
    expect($response->headers->get('Content-Security-Policy'))->toBe(implode('; ', [
        "default-src 'none'", "script-src {$self}", "style-src {$self} 'unsafe-inline'", "img-src {$self} data: blob:",
        "font-src {$self} data:", "connect-src 'none'", "object-src 'none'", "base-uri 'none'", "form-action 'none'",
        "frame-ancestors {$self}", 'sandbox allow-scripts',
    ]))->and($response->headers->get('Content-Security-Policy'))->not->toContain('allow-same-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('geolocation=()');
});

it('embeds the v1 builder preview without allow-same-origin and talks to it by window, not origin', function (): void {
    $html = (string) $this->actingAs($this->designer)->get("/admin/{$this->org->slug}/forms/{$this->form->getKey()}/edit")
        ->assertOk()->getContent();

    expect(iframeSandboxes($html))->toBe(['allow-scripts'])
        ->and($html)->not->toContain('allow-same-origin')
        // Updates: target origin '*' (an opaque origin has no name); ready: source and opaque origin checked.
        ->and($html)->toContain("definition: JSON.parse(this.definition) }, '*')")
        ->and($html)->toContain("event.source === frame.contentWindow && event.origin === 'null'")
        ->and($html)->not->toContain('window.location.origin');
});

it('embeds the v2 builder preview without allow-same-origin and nonces its loader script', function (): void {
    $response = $this->actingAs($this->designer)->get("/admin/{$this->org->slug}/forms/{$this->form->getKey()}/canvas")->assertOk();
    $html = (string) $response->getContent();
    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);

    expect(iframeSandboxes($html))->toBe(['allow-scripts'])
        ->and($html)->not->toContain('allow-same-origin')
        ->and($html)->toMatch('/<script\s+nonce="'.preg_quote($nonce[1] ?? 'missing', '/').'"\s*>\s*\(\(\) => \{\s*const register/');
});

it('lets the web server serve the preview assets to the opaque origin (CORS) and route the shells through Laravel', function (): void {
    $caddyfile = (string) file_get_contents(base_path('docker/app/Caddyfile'));

    expect($caddyfile)->toContain('@previewAssets path /form-preview/assets/*')
        ->and($caddyfile)->toContain('header @previewAssets Access-Control-Allow-Origin *')
        ->and($caddyfile)->toContain('@spaShell path /app/index.html /form-preview/preview.html')
        ->and($caddyfile)->toContain('rewrite @spaShell /index.php')
        ->and($caddyfile)->toContain('header X-Content-Type-Options nosniff');
});
