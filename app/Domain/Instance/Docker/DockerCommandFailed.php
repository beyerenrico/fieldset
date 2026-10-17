<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * A docker/compose call exited non-zero. Callers map it to the exit code of their phase (3, 4, 9 …); uncaught it
 * is an internal error. The message names the subcommand only; argv and environment values are never included.
 */
final class DockerCommandFailed extends InstanceException
{
    public function __construct(
        public readonly string $subcommand,
        public readonly ProcessOutcome $outcome,
    ) {
        $reason = $outcome->timedOut ? 'timed out' : "exited with {$outcome->exitCode}";
        parent::__construct(
            "`docker {$subcommand}` {$reason}.",
            ExitCode::InternalError,
            'docker_command_failed',
            null,
            $outcome->timedOut,
            ['stderr' => $outcome->stderrTail()],
        );
    }
}
