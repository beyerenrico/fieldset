<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

/**
 * The single definition of every Content-Security-Policy the application sends (ADR 0040).
 *
 * - Html (admin, /platform, auth pages): Filament 5 and Livewire 4 put the per-request nonce on their
 *   inline scripts (Vite::cspNonce()); Alpine's standard build evaluates expressions with Function(),
 *   hence 'unsafe-eval'; Filament renders style attributes, hence style-src 'unsafe-inline'.
 * - FieldApp: a static Vite build without inline scripts.
 * - Preview: sandboxed (opaque origin, QUESTIONS #10) and fed by postMessage only — no network access,
 *   no forms, framed by the builder only. Origins are named explicitly besides 'self', because older
 *   engines resolved 'self' against the opaque origin of a sandboxed document.
 */
final readonly class ContentSecurityPolicy
{
    /**
     * @param  string  $origin  scheme://host[:port] of the request
     * @param  string|null  $hot  origin of a running Vite dev server (public/hot), development only
     */
    public function __construct(
        private string $origin,
        private ?string $nonce = null,
        private bool $secure = false,
        private ?string $hot = null,
    ) {}

    public function for(Surface $surface): string
    {
        $directives = match ($surface) {
            Surface::Html => $this->html(),
            Surface::FieldApp => $this->fieldApp(),
            Surface::Preview => $this->preview(),
            Surface::Api => ['default-src' => ["'none'"], 'frame-ancestors' => ["'none'"]],
            Surface::Horizon => $this->horizon(),
            Surface::Vendor => $this->vendor(),
        };

        if ($this->secure && $surface !== Surface::Api) {
            $directives['upgrade-insecure-requests'] = [];
        }

        return $this->render($directives);
    }

    /** @return array<string, list<string>> */
    private function html(): array
    {
        $hot = $this->hot === null ? [] : [$this->hot];
        $socket = $this->hot === null ? [] : [(string) preg_replace('/^http/', 'ws', $this->hot)];

        return [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", ...$this->nonceSource(), "'unsafe-eval'", ...$hot],
            'style-src' => ["'self'", "'unsafe-inline'", ...$hot],
            'img-src' => ["'self'", 'data:', 'blob:', ...$hot],
            'font-src' => ["'self'", 'data:', ...$hot],
            'connect-src' => ["'self'", ...$hot, ...$socket],
            'media-src' => ["'self'", 'blob:'],
            'frame-src' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];
    }

    /** @return array<string, list<string>> */
    private function fieldApp(): array
    {
        return [
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];
    }

    /** @return array<string, list<string>> */
    private function preview(): array
    {
        $self = ["'self'", $this->origin];

        return [
            'default-src' => ["'none'"],
            'script-src' => $self,
            'style-src' => [...$self, "'unsafe-inline'"],
            'img-src' => [...$self, 'data:', 'blob:'],
            'font-src' => [...$self, 'data:'],
            'connect-src' => ["'none'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'none'"],
            'form-action' => ["'none'"],
            'frame-ancestors' => $self,
            'sandbox' => ['allow-scripts'],
        ];
    }

    /** @return array<string, list<string>> */
    private function horizon(): array
    {
        return [
            'default-src' => ["'self'"],
            // Horizon inlines its bundle (nonce) and compiles in-DOM Vue templates at runtime.
            'script-src' => ["'self'", ...$this->nonceSource(), "'unsafe-eval'"],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net'],
            'img-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];
    }

    /**
     * Scramble's API docs (Stoplight Elements from unpkg, schema compilation with eval) and Laravel's /up
     * page (CDN Tailwind): no script restrictions we could keep without breaking them.
     *
     * @return array<string, list<string>>
     */
    private function vendor(): array
    {
        return [
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];
    }

    /** @return list<string> */
    private function nonceSource(): array
    {
        return $this->nonce === null || $this->nonce === '' ? [] : ["'nonce-{$this->nonce}'"];
    }

    /** @param  array<string, list<string>>  $directives */
    private function render(array $directives): string
    {
        $parts = [];
        foreach ($directives as $name => $sources) {
            $parts[] = trim($name.' '.implode(' ', array_unique($sources)));
        }

        return implode('; ', $parts);
    }
}
