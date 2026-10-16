<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;

/**
 * The /api/v1 envelope: { success, data, error: {code, message, details} | null, meta } (§10).
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function ok(mixed $data = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data,
            'error' => null,
            'meta' => $meta,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public static function error(string $code, string $message, int $status, ?array $details = null, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'data' => null,
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'meta' => null,
        ], $status, $headers);
    }

    /** Converts a Laravel dot path (`items.2.qty`) into a JSON Pointer (`/items/2/qty`). */
    public static function pointer(string $dotPath): string
    {
        if ($dotPath === '') {
            return '';
        }

        $segments = array_map(
            static fn (string $s): string => str_replace(['~', '/'], ['~0', '~1'], $s),
            explode('.', $dotPath),
        );

        return '/'.implode('/', $segments);
    }
}
