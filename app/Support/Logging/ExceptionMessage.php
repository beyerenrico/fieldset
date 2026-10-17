<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Domain\Audit\ErrorText;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * An exception message safe for logs and error reporting (BUILD_PROMPT §11, ADR 0040):
 * - database errors keep the SQLSTATE, the server's first line and the SQL with `?` placeholders, but
 *   never the bound values nor PostgreSQL's DETAIL ("Key (email)=(…)", "Failing row contains (…)");
 * - other messages go through the audit ErrorText scrubber (URL credentials, `key=value` secrets, the
 *   application path) and lose URL query strings, which carry tokens of webhook receivers and signed links.
 */
final class ExceptionMessage
{
    public static function safe(Throwable $e): string
    {
        return match (true) {
            $e instanceof QueryException => self::database($e->getPrevious() ?? $e, $e->getSql()),
            $e instanceof PDOException => self::database($e, null),
            default => self::text($e->getMessage()),
        };
    }

    /** Any other log text: the ErrorText scrubber plus URLs without query strings. */
    public static function text(string $text): string
    {
        return self::withoutQueries(ErrorText::scrub($text));
    }

    private static function database(Throwable $driver, ?string $sql): string
    {
        $message = trim(explode('DETAIL:', $driver->getMessage(), 2)[0]);

        return ErrorText::scrub($message.($sql === null ? '' : " (SQL: {$sql})"));
    }

    private static function withoutQueries(string $text): string
    {
        return (string) preg_replace('#\b([a-z][a-z0-9+.-]*://[^\s?\#"\'<>]+)\?[^\s"\'<>]*#i', '$1?'.'[redacted]', $text);
    }
}
