<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use Carbon\CarbonImmutable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Progress on stderr (KTD3): with --json one NDJSON event per line `{ts, phase, level, message}` (plus optional
 * numeric fields for long steps, ADR 0078), otherwise a readable line. Every message passes through the command's secret redactor, so the stream never carries a
 * secret (R50).
 */
final readonly class ProgressReporter
{
    public const string INFO = 'info';

    public const string WARN = 'warn';

    public const string ERROR = 'error';

    public function __construct(
        private OutputInterface $stream,
        private bool $json,
        private SecretRedactor $redactor,
    ) {}

    public function phase(string $phase, string $message): void
    {
        $this->event($phase, self::INFO, $message);
    }

    public function warn(string $phase, string $message): void
    {
        $this->event($phase, self::WARN, $message);
    }

    public function error(string $phase, string $message): void
    {
        $this->event($phase, self::ERROR, $message);
    }

    /**
     * @param  array<string, int|string|null>  $fields  extra keys of the NDJSON event for long steps (ADR 0078):
     *                                                  `max_seconds` on a phase start, `elapsed_seconds` on its end,
     *                                                  `attempt`/`attempts` while waiting for a certificate
     */
    public function event(string $phase, string $level, string $message, array $fields = []): void
    {
        $message = $this->redactor->redactString($message);
        if ($this->json) {
            /** @var array<string, int|string|null> $fields */
            $fields = $this->redactor->redact(array_diff_key($fields, array_flip(['ts', 'phase', 'level', 'message'])));
            $event = ['ts' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'phase' => $phase, 'level' => $level, 'message' => $message, ...$fields];
            $this->stream->writeln(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return;
        }
        $prefix = match ($level) {
            self::WARN => '! ',
            self::ERROR => '✗ ',
            default => '› ',
        };
        $this->stream->writeln($prefix.$message, OutputInterface::OUTPUT_RAW);
    }
}
