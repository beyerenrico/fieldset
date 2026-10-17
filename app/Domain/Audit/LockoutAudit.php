<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Support\Facades\Cache;

/**
 * `auth.lockout` (§8.11): one entry per throttle window and address, however many attempts follow while the
 * limit holds, so unauthenticated clients cannot flood the audit log. The email is client input: stored only
 * as a string, trimmed and cut to 254 characters.
 */
final class LockoutAudit
{
    public static function record(mixed $email, string $via, ?string $ip, int $seconds = 60): void
    {
        $email = is_string($email) ? mb_substr(trim($email), 0, 254) : null;
        $key = 'audit:lockout:'.sha1($via.'|'.mb_strtolower((string) $email).'|'.$ip);
        if (! Cache::add($key, true, max(1, $seconds))) {
            return;
        }

        app(AuditLogger::class)->record('auth.lockout', null, ['email' => $email, 'via' => $via]);
    }
}
