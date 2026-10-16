<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Webhooks;

/**
 * Display form of a webhook URL (M4 review S7): receivers often put a token into the path or the query
 * (`/services/T…/B…/xxxx`, `?token=…`), so tables and the audit log show scheme, host, port, short
 * lowercase path words (up to 12 letters) and query keys only; other path segments and every query value
 * become "•••".
 */
final class WebhookUrl
{
    public const string MASK = '•••';

    public static function mask(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return self::MASK;
        }
        $masked = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '') {
            $masked .= '/'.implode('/', array_map(self::segment(...), explode('/', $path)));
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $masked .= '?'.implode('&', array_map(self::parameter(...), explode('&', $parts['query'])));
        }

        return $masked;
    }

    private static function segment(string $segment): string
    {
        return preg_match('/^[a-z][a-z._-]{0,11}$/', $segment) === 1 ? $segment : self::MASK;
    }

    private static function parameter(string $pair): string
    {
        $key = explode('=', $pair, 2)[0];

        return preg_match('/^[A-Za-z0-9._\[\]-]{1,40}$/', $key) === 1 ? $key.'='.self::MASK : self::MASK;
    }
}
