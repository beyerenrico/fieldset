<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * AE6, KTD12, ADR 0057/0082: nothing runs at the 4-hour mark (only a human command gets the Docker socket), so the app
 * answers the expired tunnel host with 503 on its own and `fsa status` only reports it. The next mutating `fsa` command
 * (`config set`, `demo`, `tunnel …`) first removes the tunnel through this sweeper, under the lock and journaled
 * (operation `tunnel`, action `expire`): runtime block, cloudflared and the app's tunnel network go, and the URL is
 * local again. A tunnel whose cloudflared stopped (it never restarts, `restart: "no"`) is removed the same way: its
 * hostname is gone for good.
 */
final readonly class TunnelSweeper
{
    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private TunnelStatus $status,
        private TunnelOperation $tunnel,
    ) {}

    /** @return array{0: string, 1: string, 2: string|null}|null the warning for the command's result, null when nothing was swept */
    public function sweep(FsaContext $context): ?array
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        // Only a tunnel fsa started (the state says so); a stray runtime block is `fsa tunnel stop`'s to clear.
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state) || ! InstanceSettings::of($state)->tunnel()) {
            return null;
        }
        $current = $this->status->of($state);
        if (! in_array($current['state'], ['expired', 'stopped'], true)) {
            return null;
        }
        $result = $this->supervisor->run(
            TunnelOperation::OPERATION,
            $this->identity->holder('fsa', $context->facts->callerUid()),
            fn (OperationRun $run): CommandResult => $this->tunnel->stopSteps($run, $current),
            context: ['action' => 'expire'],
            progress: $context->progress,
        );
        if ($result->exitCode->value !== 0) {
            return ['tunnel_cleanup_failed', 'The '.$current['state'].' quick tunnel could not be removed: '.($result->errors[0]->message ?? 'unknown error'), 'Run `fsa tunnel stop`.'];
        }
        $what = $current['state'] === 'expired' ? "expired at {$current['expires_at']}" : 'stopped running';

        return ['tunnel_removed', 'The quick tunnel'.($current['hostname'] === null ? '' : " {$current['hostname']}")." {$what} and was removed; the instance URL is ".($result->data['url'] ?? '').' again.', 'Start a new one with `fsa tunnel start`.'];
    }

    /**
     * Runs the command after a sweep and keeps the sweep in its result, also when the command itself fails: the removal
     * happened (`changed`), and the warning says so.
     *
     * @param  array{0: string, 1: string, 2: string|null}|null  $warning  what sweep() returned
     * @param  Closure(): CommandResult  $command
     */
    public static function then(string $name, ?array $warning, Closure $command): CommandResult
    {
        try {
            $result = $command();
        } catch (InstanceException $e) {
            $result = CommandResult::fromException($name, $e, $warning !== null);
        }

        return $warning === null ? $result : $result->withChanged(true)->withWarning(...$warning);
    }
}
