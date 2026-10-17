<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * Structured JSON logs (BUILD_PROMPT §11 Operability, ADR 0040): one JSON object per line with
 * `@timestamp`, `level`, `message`, `channel`, the request id (from Laravel's Context, set by
 * AssignRequestId) at the top level, redacted context/extra and exception stack traces with safe
 * messages (ExceptionMessage). The top-level message is safe too: Laravel's handler logs the raw exception
 * message (PostgreSQL DETAIL, connection and database name, URL tokens), so it becomes the exception's safe
 * message; any other message is scrubbed (ExceptionMessage::text).
 *
 * Selectable without code changes: LOG_STACK=stderr and LOG_STDERR_FORMATTER=App\Support\Logging\JsonLogFormatter
 * (the stock stderr channel reads its formatter from the environment), or a dedicated channel (ADR 0040).
 */
final class JsonLogFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, appendNewline: true, ignoreEmptyContextAndExtra: true, includeStacktraces: true);
        $this->setBasePath(base_path());
    }

    /** @return array<string, mixed> */
    protected function normalizeRecord(LogRecord $record): array
    {
        $requestId = $record->extra['request_id'] ?? null;
        $extra = $record->extra;
        unset($extra['request_id']);

        // Normalize first (models, DTOs, exceptions become arrays), then redact what came out.
        $normalized = parent::normalizeRecord($record->with(extra: $extra));

        return array_filter([
            '@timestamp' => $normalized['datetime'] ?? null,
            'level' => strtolower($record->level->getName()),
            'message' => $this->message($record, $normalized['message'] ?? null),
            'channel' => $record->channel,
            'service' => (string) config('app.name'),
            'environment' => (string) config('app.env'),
            'request_id' => is_scalar($requestId) ? (string) $requestId : null,
            'context' => is_array($normalized['context'] ?? null) ? LogRedactor::redactPlain($normalized['context']) : null,
            'extra' => is_array($normalized['extra'] ?? null) ? LogRedactor::redactPlain($normalized['extra']) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * The record message as it may be logged: the raw message of the context exception is replaced by its safe
     * message (the whole message for Laravel's handler, which logs `$e->getMessage()`), the rest is scrubbed.
     */
    private function message(LogRecord $record, mixed $normalized): string
    {
        $message = is_string($normalized) ? $normalized : $record->message;
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable && $exception->getMessage() !== '') {
            if ($message === $exception->getMessage()) {
                return ExceptionMessage::safe($exception);
            }
            $message = str_replace($exception->getMessage(), ExceptionMessage::safe($exception), $message);
        }

        return $message === '' ? '' : ExceptionMessage::text($message);
    }

    /** Exception messages (and those of previous exceptions) without bound values or secrets. */
    protected function normalizeException(Throwable $e, int $depth = 0): array
    {
        $data = parent::normalizeException($e, $depth);
        if (array_key_exists('message', $data)) {
            $data['message'] = ExceptionMessage::safe($e);
        }

        return $data;
    }
}
