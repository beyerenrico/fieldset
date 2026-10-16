<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps non-multipart request bodies on /api/* at `submissions.max_payload_bytes` (413 `payload_too_large`).
 * Global and prepended, so it runs before TrimStrings/ConvertEmptyStringsToNull decode JSON bodies.
 * Multipart uploads (attachments) keep their own per-file limits.
 */
final class LimitApiPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*') && ! str_starts_with((string) $request->headers->get('Content-Type', ''), 'multipart/form-data')) {
            $max = (int) config('submissions.max_payload_bytes', 2 * 1024 * 1024);
            $declared = $request->headers->get('Content-Length');

            if (($declared !== null && (int) $declared > $max) || strlen((string) $request->getContent()) > $max) {
                throw new ApiException('payload_too_large', __('api.payload_too_large'), 413);
            }
        }

        return $next($request);
    }
}
