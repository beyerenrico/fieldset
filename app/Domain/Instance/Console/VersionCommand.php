<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Throwable;

/**
 * `fieldset version` (§7, KTD1): the version of this fieldset (the image), of the instance in the current or given
 * directory if there is one, and the newest known release (null: the opt-in update check runs in the app and keeps its
 * result in the app's cache, which fieldset does not read; `/platform` shows it, ADR 0089).
 * The only fieldset command that runs without an instance directory, a database or Docker.
 */
#[Description('Show the versions of fieldset, the instance and the newest known release')]
#[Signature('fieldset:version')]
final class VersionCommand extends FieldsetCommand
{
    protected function requiresInstance(): bool
    {
        return false;
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        $version = config('app.version');
        $revision = config('app.revision');

        return CommandResult::ok('version', [
            'fieldset' => is_string($version) ? $version : null,
            'revision' => is_string($revision) && $revision !== '' ? $revision : null,
            'instance' => $this->instanceVersion($context),
            'newest_known' => null,
        ]);
    }

    private function instanceVersion(FieldsetContext $context): ?string
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
