<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Lifecycle\StatusReport;
use App\Domain\Instance\Output\CommandResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa status` (§7, R45): version, mode, URL, mail mode, services and their health, an unfinished operation,
 * the lock holder, unused sign-in links and disk. Read-only: it changes nothing and never prints a secret.
 */
#[Description('Show services, health, version, mode, URL and mail mode of the instance')]
#[Signature('fsa:status')]
final class StatusCommand extends FsaCommand
{
    protected function perform(FsaContext $context): CommandResult
    {
        return $this->laravel->make(StatusReport::class)->build();
    }
}
