<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Throwable;

/**
 * `fsa version` (§7, KTD1): the version of this fsa (the image), of the instance in the current or given
 * directory if there is one, and the newest known release (the update check, U20, is off by default, so null).
 * The only fsa command that runs without an instance directory, a database or Docker.
 */
#[Description('Show the versions of fsa, the instance and the newest known release')]
#[Signature('fsa:version')]
final class VersionCommand extends FsaCommand
{
    protected function requiresInstance(): bool
    {
        return false;
    }

    protected function perform(FsaContext $context): CommandResult
    {
        $version = config('app.version');
        $revision = config('app.revision');

        return CommandResult::ok('version', [
            'fsa' => is_string($version) ? $version : null,
            'revision' => is_string($revision) && $revision !== '' ? $revision : null,
            'instance' => $this->instanceVersion($context),
            'newest_known' => null,
        ]);
    }

    private function instanceVersion(FsaContext $context): ?string
    {
        $option = $this->option('dir');
        $path = is_string($option) && $option !== '' ? $option : ($context->facts->instanceDir ?? (string) getcwd());

        try {
            return str_starts_with($path, '/') && InstanceDirectory::isMarked($path) ? InstanceDirectory::open($path)->state()->version() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
