<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the Field App shell (/app/*, Vite base '/app/') and the builder preview (/form-preview/),
 * built by `npm run build` into public/app and public/form-preview (BUILD_PROMPT §6.1). Static assets are
 * served by the web server; this controller only answers for client-side routes.
 *
 * Security headers come from the global SecurityHeaders middleware, which defines one CSP per surface
 * (ContentSecurityPolicy, ADR 0040): the preview is sandboxed into an opaque origin there.
 */
final class SpaController
{
    public function app(): Response
    {
        return $this->html(public_path('app/index.html'));
    }

    public function preview(): Response
    {
        return $this->html(public_path('form-preview/preview.html'));
    }

    private function html(string $path): Response
    {
        if (! is_file($path)) {
            return new Response('The Field App has not been built yet (npm run build).', 503, ['Content-Type' => 'text/plain']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
