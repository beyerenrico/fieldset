<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Demo\DemoOperation;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Tunnel\TunnelOperation;

/**
 * An earlier operation stopped half-way (R54). Nothing new was started (exit 9); the operator resumes it by
 * running the same command again with the same flags (as `fsa status` says). For `config set` the exact command
 * line, options included, is rebuilt from the journal (`data.command`, `data.options`; ADR 0078); `fsa demo` keeps its
 * `--rotate-passwords`. Every unfinished `fsa tunnel …` (a start whose revert failed, a cut-off stop or renew) is
 * finished by `fsa tunnel stop`, which removes the tunnel whatever state it was left in (ADR 0082).
 */
final class OperationUnfinished extends InstanceException
{
    public function __construct(public readonly JournalRecord $record)
    {
        $operation = $record->operation();
        $phase = $record->lastStartedPhase();
        $command = self::commandLine($record);
        parent::__construct(
            'An earlier `'.self::cutOff($record).'` did not finish (last phase: '.($phase ?? 'none').').',
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

        return match (true) {
            $request instanceof ConfigRequest => $request->commandLine(),
            $record->operation() === TunnelOperation::OPERATION => 'fsa tunnel stop',
            $record->operation() === 'restore' && is_string($record->context()['backup'] ?? null) => 'fsa restore '.$record->context()['backup'],
            self::rotatesDemoPasswords($record) => 'fsa demo --rotate-passwords',
            default => 'fsa '.$record->operation(),
        };
    }

    /** The command that was cut off (for a tunnel journal not the one that finishes it: `fsa tunnel start`, `… renew`). */
    public static function cutOff(JournalRecord $record): string
    {
        return $record->operation() === TunnelOperation::OPERATION ? 'fsa tunnel '.self::action($record) : self::commandLine($record);
    }

    /** @return array<string, string|true> the options of that command (`config set`, `demo`; install repeats its own flags) */
    public static function options(JournalRecord $record): array
    {
        $request = $record->operation() === 'config' ? ConfigRequest::fromContext($record->context()) : null;

        return $request?->options() ?? (self::rotatesDemoPasswords($record) ? ['--rotate-passwords' => true] : []);
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
            'restore' => "Run `{$command}` again with the same flags to resume it (a swap that did not pass its verification is undone first), or `fsa restore --abandon` to bring the instance back as it was before.",
            TunnelOperation::OPERATION => "Run `{$command}`: it finishes the interrupted `".self::cutOff($record).'`, removes the tunnel whatever state it was left in and brings the stack back to the local URL; `fsa tunnel start` starts a new one afterwards.'.(self::action($record) === 'renew' ? ' `fsa tunnel renew` finishes a cut-off renew too and keeps a running tunnel.' : ''),
            default => "Run `{$command}` again with the same flags to resume it.",
        };
    }

    private static function rotatesDemoPasswords(JournalRecord $record): bool
    {
        return $record->operation() === DemoOperation::OPERATION && ($record->context()['rotate'] ?? false) === true;
    }

    private static function action(JournalRecord $record): string
    {
        $action = $record->context()['action'] ?? null;

        return is_string($action) ? $action : 'start';
    }
}
