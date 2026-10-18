<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Demo\DemoCredentials;
use App\Domain\Instance\Demo\DemoData;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ConfirmationRequired;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Closure;
use Throwable;

/**
 * `fsa tunnel start|stop|renew` (§7, E11, R20, R29, R61, R65, AE6; KTD12; ADR 0057, ADR 0082), each under the instance
 * lock with a journal (operation `tunnel`, R54).
 *
 * start: local mode only, neither local CA nor another tunnel (R61, exit 2) → the accounts (one read-only one-off):
 * another account on `password` exits 3, organizations besides the demo need `--yes` (exit 6) → journaled: demo
 * accounts still on `password` get random passwords first (R29) → cloudflared joins the stack → its hostname comes
 * from `/quicktunnel` → runtime.json records hostname, expiry (4 hours), cloudflared's address and `--tunnel-admin` →
 * the app is verified and the workers start. A failure after the stack changed restores the previous one (exit 5).
 * renew: a new expiry while cloudflared lives. stop: the tunnel goes and the URL is local again.
 */
final readonly class TunnelOperation
{
    public const string OPERATION = 'tunnel';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private TunnelStack $stack,
        private TunnelStatus $status,
        private AppCommands $app,
        private DemoData $demo,
    ) {}

    /**
     * @param  bool  $confirmed  --yes, or the install that asked for the tunnel on its command line
     * @param  (Closure(string): bool)|null  $confirm  asks in a terminal; null when the command may not prompt
     */
    public function start(FsaContext $context, bool $admin, bool $confirmed, ?Closure $confirm, bool $secretsToFile): CommandResult
    {
        $state = $this->installed();
        TunnelRules::assertCanStart($state);
        $current = $this->status->of($state);
        if ($current['state'] === 'active') {
            return TunnelResults::running($state, $current, $admin);
        }
        $project = ComposeProject::forInstance($state, $this->directory);
        $exposure = ExposureCheck::run($this->app, $project);
        $exposure->refuseOtherDefaults('the quick tunnel');
        $this->confirmOrganizations($exposure, $confirmed, $confirm);
        $uid = $context->facts->callerUid();

        return $this->supervisor->run(
            self::OPERATION,
            $this->identity->holder('fsa', $uid),
            fn (OperationRun $run): CommandResult => $this->startSteps($run, $project, $exposure, $admin, $context, $secretsToFile),
            context: ['action' => 'start', 'admin' => $admin],
            progress: $context->progress,
        );
    }

    public function renew(FsaContext $context): CommandResult
    {
        $state = $this->installed();
        $current = $this->status->of($state);
        if ($current['state'] !== 'active') {
            throw new InvalidInput('No quick tunnel runs on this instance.', 'no_tunnel', 'Start one with `fsa tunnel start`.', ['tunnel' => $current]);
        }

        return $this->supervisor->run(
            self::OPERATION,
            $this->identity->holder('fsa', $context->facts->callerUid()),
            function (OperationRun $run) use ($state, $current, $context): CommandResult {
                $tunnel = $run->phase('renew', fn (): array => $this->stack->renew($state, $context->progress), maxSeconds: QuickTunnelHostname::maxSeconds());

                return TunnelResults::renewed($state, TunnelResults::tunnel($tunnel), $current);
            },
            context: ['action' => 'renew'],
            progress: $context->progress,
        );
    }

    public function stop(FsaContext $context): CommandResult
    {
        $state = $this->installed();
        $current = $this->status->of($state);
        if ($current['state'] === 'none') {
            return TunnelResults::notRunning($state);
        }

        return $this->supervisor->run(
            self::OPERATION,
            $this->identity->holder('fsa', $context->facts->callerUid()),
            fn (OperationRun $run): CommandResult => $this->stopSteps($run, $current),
            resume: true, // finishes any unfinished tunnel journal: a start whose revert failed, an interrupted stop
            context: ['action' => 'stop'],
            progress: $context->progress,
        );
    }

    /**
     * Removes the tunnel inside an operation: `stop`, and the expiry cleanup of the next mutating command (TunnelSweeper).
     *
     * @param  array<string, mixed>  $current  TunnelStatus::of()
     */
    public function stopSteps(OperationRun $run, array $current): CommandResult
    {
        $state = $this->directory->state();
        $services = InstanceSettings::of($state)->tunnel() || $current['hostname'] !== null
            ? $run->phase('remove', fn (): array => $this->stack->remove($state), maxSeconds: 2 * TunnelRules::STACK_SECONDS)
            : [];

        return TunnelResults::stopped($this->directory->state(), $current, $services);
    }

    private function startSteps(OperationRun $run, ComposeProject $project, ExposureCheck $exposure, bool $admin, FsaContext $context, bool $toFile): CommandResult
    {
        $rotation = $exposure->demoDefaults === [] ? null : $this->demo->rotation($run, $project, $context->facts->callerUid());
        $before = $this->directory->state();
        $after = $this->stack->target($before);
        $change = TunnelStack::change($before, $after);
        $run->phase('preflight', fn (): array => [...$this->stack->pull($after), 'tunnel_subnet' => InstanceSettings::of($after)->tunnelSubnet()], changes: false);

        try {
            $tunnel = $run->phase('start', fn (): array => $this->stack->start($change, $admin, $context->progress), maxSeconds: TunnelRules::STACK_SECONDS + QuickTunnelHostname::maxSeconds());
            $result = TunnelResults::started($this->directory->state(), TunnelResults::tunnel($tunnel), $this->directory->root.'/fsa');
        } catch (Throwable $failure) {
            $result = $this->revert($run, $change, $failure);
        }
        if (! $rotation instanceof DemoCredentials) {
            return $result;
        }
        // The rotation stays even when the tunnel did not start: its passwords are shown in either case.
        $result = $result->withWarning('demo_passwords_rotated', 'The demo accounts still had the password "password": they got new random passwords (listed once under secrets), and their sessions and API tokens were revoked.');

        return $rotation->apply($result, $context, $toFile);
    }

    private function revert(OperationRun $run, ConfigChange $change, Throwable $failure): CommandResult
    {
        $error = $failure instanceof InstanceException ? $failure : new InstanceException($failure->getMessage(), ExitCode::InternalError, 'internal_error', null, true);

        try {
            $run->phase('revert', function () use ($change): array {
                $this->stack->revertStart($change);

                return ['restored' => true];
            }, data: ['failure' => $error->errorCode], always: true, maxSeconds: TunnelRules::STACK_SECONDS);
        } catch (Throwable $revertFailure) {
            return CommandResult::failed(self::OPERATION, ExitCode::HealthCheckFailed, $error->errorCode, $error->getMessage().' Restoring the previous stack failed too: '.$revertFailure->getMessage(), 'Run `fsa tunnel stop`, then `fsa status`.', true, $error->data, true);
        }
        $run->markReverted(['error' => $error->errorCode]);

        return CommandResult::failed(self::OPERATION, ExitCode::RolledBack, $error->errorCode, $error->getMessage().' The instance runs as before, without a tunnel.', $error->hint, $error->retryable, [...$error->data, 'reverted' => true]);
    }

    /** Organizations besides the demo become reachable from the internet (R65): `--yes`, or a person confirms. */
    private function confirmOrganizations(ExposureCheck $exposure, bool $confirmed, ?Closure $confirm): void
    {
        if ($confirmed || $exposure->nonDemoOrganizations === 0) {
            return;
        }
        $question = "This instance holds {$exposure->nonDemoOrganizations} organization(s) besides the demo. The quick tunnel makes their sign-in, the Field App and the API reachable from the internet through Cloudflare for 4 hours. Start it?";
        if (! $confirm instanceof Closure) {
            throw new ConfirmationRequired($question);
        }
        if (! $confirm($question)) {
            throw new InstanceException('Cancelled; nothing was changed.', ExitCode::AbortedUnchanged, 'cancelled');
        }
    }

    private function installed(): InstanceState
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fsa install` first (it resumes an unfinished install).');
        }

        return $state;
    }
}
