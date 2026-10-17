<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Factory of the optional `sentry` log channel (`'driver' => 'custom', 'via' => CreateSentryLogger::class`).
 * Without a valid DSN the channel discards everything, so LOG_STACK may list it unconditionally.
 *
 * Channel keys: dsn, level (error), environment (APP_ENV), release, timeout (seconds, 2).
 */
final class CreateSentryLogger
{
    /** @param  array<string, mixed>  $config */
    public function __invoke(array $config): Logger
    {
        $dsn = SentryDsn::tryParse($config['dsn'] ?? null);
        if (! $dsn instanceof SentryDsn) {
            return new Logger('sentry', [new NullHandler]);
        }

        $release = $config['release'] ?? null;
        $events = new SentryEventFactory(
            environment: (string) ($config['environment'] ?? config('app.env')),
            release: is_string($release) && $release !== '' ? $release : null,
        );

        return new Logger('sentry', [new SentryHandler($dsn, $events, (float) ($config['timeout'] ?? 2.0), $this->level($config['level'] ?? null))]);
    }

    /** A level name ('error', 'CRITICAL') or value (400); anything else means error. */
    private function level(mixed $level): Level
    {
        foreach (Level::cases() as $case) {
            if ($level === $case->value || (is_string($level) && strcasecmp($level, $case->name) === 0)) {
                return $case;
            }
        }

        return Level::Error;
    }
}
