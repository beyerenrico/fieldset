<?php

declare(strict_types=1);

namespace App\Http\Middleware\Security;

use Illuminate\Http\Request;

/**
 * The parts of the application that get different security headers (ADR 0040).
 *
 * Html covers everything rendered by Blade: the admin and /platform panels, auth pages, invitations,
 * Livewire requests and downloads. The SPA shells (Field App, builder preview) are static Vite builds.
 */
enum Surface: string
{
    case Html = 'html';
    case FieldApp = 'field-app';
    case Preview = 'preview';
    case Api = 'api';
    case Horizon = 'horizon';
    /** Third-party UIs we do not control (Scramble's Stoplight docs, Laravel's /up page): baseline policy only. */
    case Vendor = 'vendor';

    public static function of(Request $request): self
    {
        $horizon = trim((string) config('horizon.path', 'horizon'), '/');

        return match (true) {
            $request->is('api', 'api/*') => self::Api,
            $request->is('app', 'app/*') => self::FieldApp,
            $request->is('form-preview', 'form-preview/*') => self::Preview,
            $request->is($horizon, $horizon.'/*') => self::Horizon,
            $request->is('docs/api', 'docs/api.json', 'up') => self::Vendor,
            default => self::Html,
        };
    }

    /** Blade surfaces render inline scripts that must carry a per-request nonce. */
    public function usesNonce(): bool
    {
        return $this === self::Html || $this === self::Horizon;
    }
}
