<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\Adopt\AdoptOperation;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Demo\DemoOperation;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Reset\ResetOperation;
use App\Domain\Instance\Reset\ResetRequest;
use App\Domain\Instance\Reset\ResetStatus;
use App\Domain\Instance\Tunnel\TunnelOperation;

/**
 * An earlier operation stopped half-way (R54). Nothing new was started (exit 9); the operator resumes it by
 * running the same command again with the same flags (as `fsa status` says). For `config set` the exact command
 * line, options included, is rebuilt from the journal (`data.command`, `data.options`; ADR 0078); `fsa demo` keeps its
 * `--rotate-passwords`. Every unfinished `fsa tunnel …` (a start whose revert failed, a cut-off stop or renew) is
 * finished by `fsa tunnel stop`, which removes the tunnel whatever state it was left in (ADR 0082). An unfinished `fsa adopt`
 * names its checkout and the way back: `--abandon` before 2.0 started, `fsa uninstall` after (ADR 0087). A restore, a
 * rollback, an adopt and an install from a backup are rebuilt with every flag the resumed run needs (ResumeCommand, M12 gate
 * O1, O2): the identity file, the passphrase's source, the APP key file, `--accept-unauthenticated`, `--yes`.
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
            ResumeCommand::applies($record) => ResumeCommand::line($record),
            $record->operation() === 'upgrade' => 'fsa upgrade --resume',
            self::rotatesDemoPasswords($record) => 'fsa demo --rotate-passwords',
            $record->operation() === ResetOperation::OPERATION => ResetRequest::commandLine($record->context()),
            default => 'fsa '.$record->operation(),
        };
    }

    /** The command that was cut off (for a tunnel journal not the one that finishes it: `fsa tunnel start`, `… renew`). */
    public static function cutOff(JournalRecord $record): string
    {
        return match (true) {
            $record->operation() === TunnelOperation::OPERATION => 'fsa tunnel '.self::action($record),
            ResumeCommand::applies($record) => ResumeCommand::command($record),
            default => self::commandLine($record),
        };
    }

    /** @return array<string, string|true> the options of that command (`config set`, `demo`, reset, ResumeCommand; a plain install repeats its own flags) */
    public static function options(JournalRecord $record): array
    {
        $request = $record->operation() === 'config' ? ConfigRequest::fromContext($record->context()) : null;

        return $request?->options() ?? match (true) {
            ResumeCommand::applies($record) => ResumeCommand::options($record),
            self::rotatesDemoPasswords($record) => ['--rotate-passwords' => true],
            $record->operation() === ResetOperation::OPERATION => ResetRequest::options($record->context()),
            default => [],
        };
    }

    /** What to run, and what it will do. */
    public static function hint(JournalRecord $record): string
    {
        $command = self::commandLine($record);

        return match ($record->operation()) {
            'config' => $record->hasStarted('revert')
                ? "Run `{$command}` again: it finishes restoring the settings from before that change (it does not repeat the change)."
                : "Run `{$command}` again: it starts over from the settings before that change and reverts if it still fails.",
            'install' => ResumeCommand::fromBackup($record) !== null
                ? "Run `{$command}` to resume it: it checks the backup again, so it needs the same identity file, passphrase and APP key file (a plain `fsa install` cannot take it over). `fsa uninstall` removes a half-finished install."
                : "Run `{$command}` again with the same flags to resume it. `fsa uninstall` removes a half-finished install.",
            'restore' => "Run `{$command}` to resume it: before the swap its steps run again from a clean scratch state; a half-done swap is undone; a completed swap is verified again (and undone if that fails). Or `fsa restore --abandon --yes` brings the instance back as it was before.",
            'upgrade' => "Run `{$command}` once `fsa status` shows `supervisor.running: false` (while it runs, `fsa upgrade --follow` follows it; ADR 0086): before the safety backup it brings the previous services back (then run `fsa upgrade` again), from the safety backup until the commit it rolls back to the safety backup, after the commit it only finishes opening.",
            'rollback' => "Run `{$command}` to resume it: before the swap its steps run again; a half-done swap returns to the upgraded release; a completed swap is verified again (and returns if that fails).",
            ResetOperation::OPERATION => ResetStatus::hint($record),
            AdoptOperation::OPERATION => $record->hasStarted('start')
                ? "Run `{$command}` again to finish it (2.0 already runs on its copy), or `fsa uninstall` (a final backup first) and `docker compose up -d` in the 1.0 checkout to go back to 1.0."
                : "Run `{$command}` again to resume it, or `fsa adopt --abandon --yes` to start 1.0 again as it was (ADR 0087).",
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
