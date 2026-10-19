<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Demo\DemoOperation;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Tunnel\TunnelSweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa demo` (§7, R29, R30, F2, ADR 0081): adds the demo organization, forms, workflow and users to an installed
 * instance and hands out the demo owner's sign-in link; `--rotate-passwords` gives every demo account a new random
 * password (listed once) and revokes its sessions and API tokens. A new local demo is `install.sh --demo`
 * (`fsa install --demo`): the shim exists only once an instance does. Socket-less one-offs do the app work (KTD25).
 */
#[Description('Add the demo organization and users (random passwords unless loopback-only), or rotate their passwords')]
#[Signature('fsa:demo
        {--rotate-passwords : Give every demo user a new random password (listed once) and revoke their sessions, API tokens and sign-in links}
        {--open : Open the demo owner\'s sign-in link in the browser of a desktop session instead of printing it}')]
final class DemoCommand extends FsaCommand
{
    protected function opensBrowser(): bool
    {
        return (bool) $this->option('open');
    }

    protected function perform(FsaContext $context): CommandResult
    {
        // An expired or stopped quick tunnel goes first (AE6, ADR 0082): this is a mutating command.
        $swept = $this->laravel->make(TunnelSweeper::class)->sweep($context);

        return TunnelSweeper::then($this->resultName(), $swept, fn (): CommandResult => $this->laravel->make(DemoOperation::class)->run(
            $context,
            (bool) $this->option('rotate-passwords'),
            is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '',
        ));
    }
}
