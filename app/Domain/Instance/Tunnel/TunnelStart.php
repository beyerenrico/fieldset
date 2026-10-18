<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Demo\DemoCredentials;
use App\Domain\Instance\Demo\DemoData;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ConfirmationRequired;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The journaled part of `fsa tunnel start` (operation `tunnel`, action `start`; R20, R29, R65; ADR 0078, ADR 0082),
 * under the instance lock and guarded against SIGTERM, SIGINT and SIGHUP (Interruption):
 *
 * accounts (the exposure check again, now under the lock: another account on `password` exits 3, organizations besides
 * the demo that nobody confirmed exit 6) → preflight (the target state with its subnet, cloudflared's image; exit 3) →
 * demo_rotate (demo accounts still on `password` get random passwords) → start (cloudflared in the stack, the hostname,
 * runtime.json, verification, the workers) → [revert].
 *
 * Nothing changes before demo_rotate, so a refused check, a failed pull or a missing subnet loses no password and a
 * signal there aborts with exit 9. From the rotation on, every failure reverts to the previous stack (exit 5) and the
 * result keeps the rotated passwords (secrets, warning `demo_passwords_rotated`, `changed: true`). The rotation runs
 * disarmed (its one-off must finish, the passwords exist only in its answer); a signal during it or during start ends
 * in the revert, which runs to its end. A revert that fails too is exit 8; its journal stays open for `fsa tunnel stop`.
 */
final readonly class TunnelStart
{
    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private TunnelStack $stack,
        private AppCommands $app,
        private DemoData $demo,
        private Interruption $interruption,
    ) {}

    /** @param  bool  $confirmed  organizations besides the demo may be exposed (`--yes`, a person, or the install's command line) */
    public function run(FsaContext $context, ComposeProject $project, bool $admin, bool $confirmed, bool $toFile): CommandResult
    {
        return $this->interruption->guard(fn (): CommandResult => $this->supervisor->run(
            TunnelOperation::OPERATION,
            $this->identity->holder('fsa', $context->facts->callerUid()),
            function (OperationRun $run) use ($project, $admin, $confirmed, $context, $toFile): CommandResult {
                try {
                    return $this->steps($run, $project, $admin, $confirmed, $context, $toFile);
                } finally {
                    $this->interruption->disarm(); // a late signal must not throw out of the supervisor's own handling
                }
            },
            context: ['action' => 'start', 'admin' => $admin],
            progress: $context->progress,
        ));
    }

    private function steps(OperationRun $run, ComposeProject $project, bool $admin, bool $confirmed, FsaContext $context, bool $toFile): CommandResult
    {
        $this->interruption->arm();
        $exposure = $this->exposure($run, $project, $confirmed);
        $change = $this->preflight($run);
        $rotation = null;

        try {
            $this->interruption->disarm(); // the rotation's one-off must finish: its passwords exist only in its answer
            $rotation = $exposure->demoDefaults === [] ? null : $this->demo->rotation($run, $project, $context->facts->callerUid());
            $this->interruption->arm(); // a signal received during the rotation interrupts here
            $tunnel = $run->phase('start', fn (): array => $this->stack->start($change, $admin, $context->progress), maxSeconds: TunnelRules::STACK_SECONDS + QuickTunnelHostname::maxSeconds());
            $this->interruption->disarm(); // the tunnel runs and is recorded: a signal no longer reverts it
            $result = TunnelResults::started($this->directory->state(), TunnelResults::tunnel($tunnel), $this->fsa());
        } catch (Throwable $failure) {
            $this->interruption->consume(); // first: a signal now must not throw out of this handler

            $result = $this->revert($run, $change, $failure, $admin);
        }

        return $rotation instanceof DemoCredentials ? $this->withRotation($result, $rotation, $context, $toFile) : $result;
    }

    /** The accounts as they are now that nothing else can change the instance (R29, R65), journaled as counts only. */
    private function exposure(OperationRun $run, ComposeProject $project, bool $confirmed): ExposureCheck
    {
        $exposure = null;
        $run->phase(ExposureCheck::PHASE, function () use ($project, &$exposure): array {
            $exposure = ExposureCheck::run($this->app, $project);

            return $exposure->counts();
        }, changes: false, maxSeconds: ExposureCheck::maxSeconds());
        if (! $exposure instanceof ExposureCheck) {
            throw new InstanceException('The account check returned no result.');
        }
        $exposure->refuseOtherDefaults('the quick tunnel');
        if (! $confirmed && $exposure->nonDemoOrganizations > 0) {
            throw new ConfirmationRequired(TunnelRules::exposureQuestion($exposure->nonDemoOrganizations));
        }

        return $exposure;
    }

    /** The state with the tunnel (its subnet chosen once) and cloudflared's image, before anything changes (exit 3). */
    private function preflight(OperationRun $run): ConfigChange
    {
        $before = $this->directory->state();
        $after = null;
        $run->phase('preflight', function () use ($before, &$after): array {
            $after = $this->stack->target($before);

            return [...$this->stack->pull($after), 'tunnel_subnet' => InstanceSettings::of($after)->tunnelSubnet()];
        }, changes: false);
        if (! $after instanceof InstanceState) {
            throw new InstanceException('The tunnel preflight returned no target.');
        }

        return TunnelStack::change($before, $after);
    }

    private function revert(OperationRun $run, ConfigChange $change, Throwable $failure, bool $admin): CommandResult
    {
        $error = TunnelResults::failure($failure, $change);
        $steps = [];

        try {
            $run->phase('revert', function () use ($change, &$steps): array {
                $this->stack->revertStart($change, static function (string $step) use (&$steps): void {
                    $steps[] = $step;
                });

                return ['restored' => true];
            }, data: ['failure' => $error['code']], always: true, maxSeconds: TunnelRules::STACK_SECONDS);
        } catch (Throwable $revertFailure) {
            $run->note('revert_failed', ['completed' => $steps]);

            return TunnelResults::revertFailed($this->directory->state(), $error, TunnelResults::failure($revertFailure, $change), $steps, $this->stack->stopFrontends($change->before));
        }
        $run->markReverted(['error' => $error['code']]);

        return TunnelResults::rolledBack($this->directory->state(), $error, $admin, $this->fsa());
    }

    /** The rotation stays whatever became of the tunnel: its passwords are listed once, and something changed. */
    private function withRotation(CommandResult $result, DemoCredentials $rotation, FsaContext $context, bool $toFile): CommandResult
    {
        $result = $result->withChanged(true)->withWarning('demo_passwords_rotated', 'The demo accounts still had the password "password": they got new random passwords (listed once under secrets), and their sessions and API tokens were revoked.');

        return $rotation->apply($result, $context, $toFile);
    }

    private function fsa(): string
    {
        return $this->directory->root.'/fsa';
    }
}
