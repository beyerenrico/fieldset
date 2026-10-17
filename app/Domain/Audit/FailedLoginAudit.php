<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `auth.failed` (§8.11) with a cap per IP: at most LIMIT entries per WINDOW_MINUTES, then one
 * `auth.failed_suppressed` entry and nothing more until the window ends. Every new address gets its own
 * login throttle, so without the cap one client could write an undeletable entry (retention ≥ 30 days) per
 * request into the append-only log.
 */
final class FailedLoginAudit
{
    public const int LIMIT = 20;

    public const int WINDOW_MINUTES = 10;

    /** @param  array<string, mixed>  $properties */
    public static function record(?Model $user, array $properties, ?string $ip): void
    {
        $attempt = RateLimiter::hit('audit:failed:'.sha1((string) $ip), self::WINDOW_MINUTES * 60);

        if ($attempt <= self::LIMIT) {
            app(AuditLogger::class)->record('auth.failed', $user, $properties);
        } elseif ($attempt === self::LIMIT + 1) {
            app(AuditLogger::class)->record('auth.failed_suppressed', null, ['limit' => self::LIMIT, 'window_minutes' => self::WINDOW_MINUTES]);
        }
    }
}
