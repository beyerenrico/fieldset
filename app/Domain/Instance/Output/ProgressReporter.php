<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use Carbon\CarbonImmutable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Progress on stderr (KTD3): with --json one NDJSON event per line `{ts, phase, level, message}`, otherwise a
 * readable line. Every message passes through the command's secret redactor, so the stream never carries a
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

    public function event(string $phase, string $level, string $message): void
    {
        $message = $this->redactor->redactString($message);
        if ($this->json) {
            $event = ['ts' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'phase' => $phase, 'level' => $level, 'message' => $message];
            $this->stream->writeln(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

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
