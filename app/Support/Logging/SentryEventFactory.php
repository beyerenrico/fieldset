<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Maps a log record (Laravel reports exceptions as `context.exception`) to a Sentry event. No request
 * bodies, headers, cookies or frame variables are sent; context is redacted (LogRedactor) and exception
 * messages are made safe (ExceptionMessage: no SQL bindings, secrets or URL query strings).
 */
final readonly class SentryEventFactory
{
    private const int MAX_FRAMES = 50;

    public function __construct(
        private string $environment,
        private ?string $release = null,
    ) {}

    /** @return array<string, mixed> */
    public function make(LogRecord $record): array
    {
        $exception = $record->context['exception'] ?? null;
        $context = $record->context;
        unset($context['exception']);
        $context = LogRedactor::redactPlain($context);

        return array_filter([
            'event_id' => str_replace('-', '', (string) Str::uuid()),
            'timestamp' => (float) $record->datetime->format('U.u'),
            'platform' => 'php',
            'level' => $this->level($record->level),
            'logger' => $record->channel,
            'server_name' => (string) gethostname(),
            'environment' => $this->environment,
            'release' => $this->release,
            'message' => $exception instanceof Throwable ? null : ['formatted' => $record->message],
            'exception' => $exception instanceof Throwable ? ['values' => $this->exceptions($exception)] : null,
            'tags' => array_filter(['request_id' => $this->scalar($record->extra['request_id'] ?? null)]),
            'user' => isset($context['userId']) && is_scalar($context['userId']) ? ['id' => (string) $context['userId']] : null,
            'extra' => $context === [] ? null : $context,
        ], static fn (mixed $value): bool => ! in_array($value, [null, [], ''], true));
    }

    /** @return list<array<string, mixed>> oldest cause first, the reported exception last */
    private function exceptions(Throwable $exception): array
    {
        $values = [];
        for ($e = $exception; $e instanceof Throwable && count($values) < 10; $e = $e->getPrevious()) {
            array_unshift($values, [
                'type' => $e::class,
                'value' => ExceptionMessage::safe($e),
                'stacktrace' => ['frames' => $this->frames($e)],
            ]);
        }

        return $values;
    }

    /** @return list<array<string, mixed>> caller first, the throw site last (Sentry's order) */
    private function frames(Throwable $e): array
    {
        $trace = array_slice($e->getTrace(), 0, self::MAX_FRAMES - 1);
        // Frame i ran trace[i]['function'] at its location; frame 0 is the throw site.
        $locations = [[$e->getFile(), $e->getLine()]];
        foreach ($trace as $call) {
            $locations[] = [$call['file'] ?? '[internal]', $call['line'] ?? 0];
        }

        $frames = [];
        foreach ($locations as $index => [$file, $line]) {
            $frames[] = array_filter([
                'filename' => $this->relative($file),
                'lineno' => $line,
                'function' => isset($trace[$index]) ? $this->functionName($trace[$index]) : null,
                'in_app' => ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return array_reverse($frames);
    }

    /** @param  array<string, mixed>  $frame */
    private function functionName(array $frame): string
    {
        $class = is_string($frame['class'] ?? null) ? $frame['class'].($frame['type'] ?? '::') : '';

        return $class.(is_string($frame['function'] ?? null) ? $frame['function'] : '{main}');
    }

    private function relative(string $file): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private function level(Level $level): string
    {
        return match (true) {
            $level->isHigherThan(Level::Error) => 'fatal',
            $level === Level::Error => 'error',
            $level === Level::Warning => 'warning',
            $level === Level::Debug => 'debug',
            default => 'info',
        };
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
