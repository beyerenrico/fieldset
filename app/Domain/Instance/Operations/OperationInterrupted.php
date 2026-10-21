<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * A signal asked the running `fieldset` command to stop (Ctrl-C, a closed SSH session, an agent's tool timeout,
 * `docker stop`; ADR 0078). Thrown from the signal handler into the step that was running: before any change it
 * aborts with exit 9 and nothing changed; during a `config set` apply or verify it starts the revert.
 */
final class OperationInterrupted extends InstanceException
{
    public function __construct(public readonly int $signal)
    {
        parent::__construct(
            'The command was interrupted by signal '.self::name($signal).'.',
            ExitCode::AbortedUnchanged,
            'interrupted',
            'Run the command again when it can finish; nothing was changed.',
            true,
            ['signal' => self::name($signal)],
        );
    }

    public static function name(int $signal): string
    {
        return match ($signal) {
            1 => 'SIGHUP',
            2 => 'SIGINT',
            15 => 'SIGTERM',
            default => (string) $signal,
        };
    }
}
