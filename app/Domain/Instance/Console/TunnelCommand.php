<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Tunnel\TunnelOperation;
use App\Domain\Instance\Tunnel\TunnelSweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset tunnel start|stop|renew` (§7, E11, R20, R61, R65, AE6; ADR 0057, ADR 0082): an account-less cloudflared quick
 * tunnel for phones on a local install, for 4 hours unless renewed. `start` prints the HTTPS URL and warns about the
 * third party and the internet exposure; it needs `--yes` when organizations besides the demo exist (exit 6), refuses
 * while a non-demo account signs in with `password` (exit 3; demo accounts get random passwords first) and next to
 * domain mode, proxy mode or the local CA (exit 2). `--tunnel-admin` opens /admin through it too (R65). Before start and
 * renew an expired or stopped tunnel is removed first (the next mutating command does that, TunnelSweeper); stop removes
 * it itself and finishes any unfinished tunnel operation, so it runs a removal once.
 */
#[Description('Start, stop or renew the quick tunnel (HTTPS for phones through Cloudflare, 4 hours)')]
#[Signature('fieldset:tunnel
        {action : start, stop or renew}
        {--tunnel-admin : start: the admin panel (/admin) is reachable through the tunnel too}')]
final class TunnelCommand extends FieldsetCommand
{
    /** It changes the instance, so it saves the owner the shim measured with it (2.1.1 U4, KTD6). */
    protected function recordsOwner(): bool
    {
        return true;
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        $action = $this->argument('action');
        if (! in_array($action, ['start', 'stop', 'renew'], true)) {
            throw InvalidInput::usage('Use `fieldset tunnel start`, `fieldset tunnel stop` or `fieldset tunnel renew`.');
        }
        if ($action !== 'start' && $this->option('tunnel-admin') === true) {
            throw new InvalidInput('--tunnel-admin belongs to `fieldset tunnel start`.', 'invalid_flag', 'Run `fieldset tunnel stop`, then `fieldset tunnel start --tunnel-admin`.');
        }
        $operation = $this->laravel->make(TunnelOperation::class);
        $swept = $action === 'stop' ? null : $this->laravel->make(TunnelSweeper::class)->sweep($context);

        return TunnelSweeper::then($this->resultName(), $swept, fn (): CommandResult => match ($action) {
            'start' => $operation->start(
                $context,
                (bool) $this->option('tunnel-admin'),
                $context->yes,
                $this->canPrompt($context, ignoreYes: true) ? fn (string $question): bool => $this->confirm($question) : null,
                is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '',
            ),
            'renew' => $operation->renew($context),
            default => $operation->stop($context),
        });
    }
}
