<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the Field App shell (/app/*, Vite base '/app/') and the builder preview (/form-preview/),
 * built by `npm run build` into public/app and public/form-preview (BUILD_PROMPT §6.1). Static assets are
 * served by the web server; this controller only answers for client-side routes.
 */
final class SpaController
{
    public function app(): Response
    {
        return $this->html(public_path('app/index.html'), "frame-ancestors 'none'");
    }

    public function preview(): Response
    {
        // The preview is embedded by the admin builder of the same origin only.
        return $this->html(public_path('form-preview/preview.html'), "frame-ancestors 'self'");
    }

    private function html(string $path, string $frameAncestors): Response
    {
        if (! is_file($path)) {
            return new Response('The Field App has not been built yet (npm run build).', 503, ['Content-Type' => 'text/plain']);
        }

        $csp = implode('; ', [
            "default-src 'self'", "script-src 'self'", "style-src 'self' 'unsafe-inline'", "img-src 'self' data: blob:",
            "font-src 'self' data:", "connect-src 'self'", "worker-src 'self'", "manifest-src 'self'", "object-src 'none'",
            "base-uri 'self'", "form-action 'self'", $frameAncestors,
        ]);

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => $csp,
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ]);
    }
}
