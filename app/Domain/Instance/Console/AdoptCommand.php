<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Adopt\AdoptOperation;
use App\Domain\Instance\Adopt\AdoptRequest;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa adopt <checkout>` (§7, R46 as changed by R60, F7, plan U19, ADR 0087): converts a clone-based 1.0 install into an
 * instance in this directory by copying its data (AdoptOperation); 1.0's checkout and volumes stay as they were, so
 * `fsa uninstall` and `docker compose up -d` in the checkout bring 1.0 back. install.sh starts it with `--adopt <checkout>`
 * in a new directory; the launchers mount the checkout read-only at its own path. Asks first (it stops 1.0): exit 6
 * without a terminal (or with --json) unless --yes. `--abandon` undoes an adopt that stopped half-way.
 */
#[Description('Convert a 1.0 checkout install into an instance by copying its data (asks first)')]
#[Signature('fsa:adopt
        {checkout? : The 1.0 install\'s checkout: the directory `docker compose up` runs in}
        {--release= : Release version; must match the verified manifest}
        {--drop-jobs : Go on when 1.0\'s queued jobs do not drain within 2 minutes (they stay in 1.0\'s Valkey)}
        {--abandon : Undo an adopt that stopped half-way: start 1.0 again and remove what adopt created here}')]
final class AdoptCommand extends FsaCommand
{
    protected function confirmationPrompt(FsaContext $context): string
    {
        if ($this->option('abandon') === true) {
            return 'Undo the interrupted adopt: start 1.0 again and remove the containers, volumes and files it created here?';
        }
        $checkout = $this->checkout();

        return "Stop the 1.0 install in {$checkout}, back it up and copy its data into a new instance in {$context->directory()->root}? Its checkout and volumes are not changed, so 1.0 can be started again.";
    }

    protected function perform(FsaContext $context): CommandResult
    {
        $operation = $this->laravel->make(AdoptOperation::class);
        if ($this->option('abandon') === true) {
            return $operation->abandon($context);
        }
        $checkout = $this->checkout();
        $this->assertVisible($context, $checkout);
        $release = $this->option('release');

        return $operation->adopt(new AdoptRequest($checkout, is_string($release) && $release !== '' ? $release : null, $this->option('drop-jobs') === true, $context->facts->callerUid()), $context);
    }

    private function checkout(): string
    {
        $checkout = $this->argument('checkout');
        if (! is_string($checkout) || trim($checkout) === '') {
            throw new InvalidInput('Name the 1.0 checkout to adopt.', 'checkout_required', 'Pass the directory of the 1.0 install: fsa adopt /srv/form-schema-app');
        }
        $checkout = rtrim(trim($checkout), '/');

        return str_starts_with($checkout, '/') ? $checkout : getcwd().'/'.$checkout;
    }

    /** A launcher mounts the checkout read-only at its own path (FSA_MOUNTS); anything else is invisible here (exit 2). */
    private function assertVisible(FsaContext $context, string $checkout): void
    {
        $facts = $context->facts;
        if (! $facts->launched() || str_starts_with($checkout.'/', $context->directory()->root.'/')) {
            return;
        }
        $mounted = array_any(array_keys($facts->mounts), static fn (string $root): bool => $checkout === rtrim($root, '/') || str_starts_with($checkout.'/', rtrim($root, '/').'/'));
        if (! $mounted) {
            throw new InvalidInput("{$checkout} is not mounted into the fsa container.", 'path_not_mounted', 'Run adopt through install.sh (--adopt <checkout>) or the instance\'s fsa, which mount the checkout.');
        }
    }
}
