<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * The compose project of an instance: its name and the generated file. `fsa` always passes both explicitly, so
 * COMPOSE_FILE or a stray .env never selects other files (KTD6).
 */
final readonly class ComposeProject
{
    /** Compose's project-name grammar. */
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/D';

    public function __construct(
        public string $name,
        public string $file,
        public string $directory,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InstanceException("Invalid compose project name [{$name}].", ExitCode::InvalidInput, 'invalid_project_name');
        }
    }

    public static function forInstance(InstanceState $state, InstanceDirectory $directory, string $file = InstanceDirectory::COMPOSE_FILE): self
    {
        return new self($state->project(), $directory->path($file), $directory->root);
    }
}
