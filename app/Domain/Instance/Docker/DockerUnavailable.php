<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use Throwable;

/** Docker or Compose is missing, unreachable or too old (exit 10). */
final class DockerUnavailable extends InstanceException
{
    public function __construct(string $message, ?string $hint = null, ?Throwable $previous = null)
    {
        parent::__construct(
            $message,
            ExitCode::DockerUnavailable,
            'docker_unavailable',
            $hint ?? 'Check that Docker is running and that DOCKER_HOST points at the daemon.',
            true,
            [],
            $previous,
        );
    }
}
