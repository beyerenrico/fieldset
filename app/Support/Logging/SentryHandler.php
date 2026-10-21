<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Optional Sentry-compatible error reporting without an SDK dependency (BUILD_PROMPT §11, ADR 0040):
 * posts each record at or above the level as an envelope to the DSN's project. Reporting never breaks
 * the request: transport failures are swallowed (and written to PHP's error log), and a failure while
 * sending cannot recurse into another send.
 */
final class SentryHandler extends AbstractProcessingHandler
{
    public const string CLIENT = 'fieldset/1.0';

    private static bool $sending = false;

    public function __construct(
        private readonly SentryDsn $dsn,
        private readonly SentryEventFactory $events,
        private readonly float $timeoutSeconds = 2.0,
        int|string|Level $level = Level::Error,
    ) {
        parent::__construct($level, bubble: true);
    }

    protected function write(LogRecord $record): void
    {
        if (self::$sending) {
            return;
        }
        self::$sending = true;

        try {
            $event = $this->events->make($record);
            Http::timeout($this->timeoutSeconds)->connectTimeout(min(1.0, $this->timeoutSeconds))
                ->withHeaders(['X-Sentry-Auth' => $this->dsn->authHeader(self::CLIENT)])
                ->withBody($this->envelope($event), 'application/x-sentry-envelope')
                ->post($this->dsn->envelopeUrl)
                ->throw();
        } catch (Throwable $e) {
            error_log('Sentry-compatible reporting failed: '.$e->getMessage());
        } finally {
            self::$sending = false;
        }
    }

    /** @param  array<string, mixed>  $event */
    private function envelope(array $event): string
    {
        $header = ['event_id' => $event['event_id'], 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'dsn' => $this->dsn->dsn];
        $payload = (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return implode("\n", [
            (string) json_encode($header, JSON_UNESCAPED_SLASHES),
            (string) json_encode(['type' => 'event', 'length' => strlen($payload), 'content_type' => 'application/json']),
            $payload,
        ])."\n";
    }
}
