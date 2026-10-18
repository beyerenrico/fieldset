<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * An earlier operation stopped half-way (R54). Nothing new was started (exit 9); the operator resumes it by
 * running the same command again with the same flags (as `fsa status` says). For `config set` the exact command
 * line, options included, is rebuilt from the journal (`data.command`, `data.options`; ADR 0078).
 */
final class OperationUnfinished extends InstanceException
{
    public function __construct(public readonly JournalRecord $record)
    {
        $operation = $record->operation();
        $phase = $record->lastStartedPhase();
        $command = self::commandLine($record);
        parent::__construct(
            "An earlier `{$command}` did not finish (last phase: ".($phase ?? 'none').').',
            ExitCode::AbortedUnchanged,
            'operation_unfinished',
            self::hint($record),
            false,
            ['operation' => $operation, 'last_phase' => $phase, 'started_at' => $record->startedAt(), 'journal' => $record->id(), 'command' => $command, 'options' => self::options($record)],
        );
    }

    /** The command that resumes the operation: for `config set` with key, value and every option it recorded (ADR 0077). */
    public static function commandLine(JournalRecord $record): string
    {
        $request = $record->operation() === 'config' ? ConfigRequest::fromContext($record->context()) : null;

        return $request?->commandLine() ?? 'fsa '.$record->operation();
    }

    /** @return array<string, string|true> the options of that command (`config set` only; install repeats its own flags) */
    public static function options(JournalRecord $record): array
    {
        $request = $record->operation() === 'config' ? ConfigRequest::fromContext($record->context()) : null;

        return $request?->options() ?? [];
    }

    /** What to run, and what it will do. */
    public static function hint(JournalRecord $record): string
    {
        $command = self::commandLine($record);

        return match ($record->operation()) {
            'config' => $record->hasStarted('revert')
                ? "Run `{$command}` again: it finishes restoring the settings from before that change (it does not repeat the change)."
                : "Run `{$command}` again: it starts over from the settings before that change and reverts if it still fails.",
            'install' => "Run `{$command}` again with the same flags to resume it. `fsa uninstall` removes a half-finished install.",
            default => "Run `{$command}` again with the same flags to resume it.",
        };
    }
}
