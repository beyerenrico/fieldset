<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Console\FieldsetContext;
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
use Throwable;

/**
 * The journaled part of `fieldset tunnel start` (operation `tunnel`, action `start`; R20, R29, R65; ADR 0078, ADR 0082),
 * under the instance lock and guarded against SIGTERM, SIGINT and SIGHUP (Interruption):
 *
 * accounts (the exposure check again, now under the lock: another account on `password` exits 3, organizations besides
 * the demo that nobody confirmed exit 6) → preflight (the target state with its subnet, cloudflared's image; exit 3) →
 * demo_rotate (demo accounts still on `password` get random passwords) → start (cloudflared in the stack, the hostname,
 * runtime.json, verification, the workers) → [revert].
 *
 * Nothing changes before demo_rotate, so a refused check, a failed pull or a missing subnet loses no password and a
 * signal there aborts with exit 9. From the rotation on, every failure ends as exit 5 (the instance runs as before,
 * without a tunnel) and the result keeps the rotated passwords (secrets, warning `demo_passwords_rotated`, `changed:
 * true`): a failure once start began reverts the stack; one before (the rotation itself, a signal received during it)
 * leaves the untouched stack alone. The target is taken from the state as the rotation left it, so its history entry
 * stays. The rotation runs disarmed (its one-off must finish, the passwords exist only in its answer); the revert runs to
 * its end. A revert that fails too is exit 8; its journal stays open for `fieldset tunnel stop`.
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
    public function run(FieldsetContext $context, ComposeProject $project, bool $admin, bool $confirmed, bool $toFile): CommandResult
    {
        return $this->interruption->guard(fn (): CommandResult => $this->supervisor->run(
            TunnelOperation::OPERATION,
            $this->identity->holder('fieldset', $context->facts->callerUid()),
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

    private function steps(OperationRun $run, ComposeProject $project, bool $admin, bool $confirmed, FieldsetContext $context, bool $toFile): CommandResult
    {
        $this->interruption->arm();
        $exposure = $this->exposure($run, $project, $confirmed);
        $subnet = $this->preflight($run);
        $rotation = null;
        $change = null;

        try {
            $this->interruption->disarm(); // the rotation's one-off must finish: its passwords exist only in its answer
            $rotation = $exposure->demoDefaults === [] ? null : $this->demo->rotation($run, $project, $context->facts->callerUid());
            $this->interruption->arm(); // a signal received during the rotation interrupts here
            $change = $this->change($subnet); // from the state as the rotation left it: its history entry stays
            $tunnel = $run->phase('start', fn (): array => $this->stack->start($change, $admin, $context->progress), maxSeconds: TunnelRules::STACK_SECONDS + QuickTunnelHostname::maxSeconds());
            $this->interruption->disarm(); // the tunnel runs and is recorded: a signal no longer reverts it
            $result = TunnelResults::started($this->directory->state(), TunnelResults::tunnel($tunnel), $this->fieldset());
        } catch (Throwable $failure) {
            $this->interruption->consume(); // first: a signal now must not throw out of this handler

            $result = $change instanceof ConfigChange ? $this->revert($run, $change, $failure, $admin) : $this->notStarted($run, $this->change($subnet), $failure, $admin);
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

    /**
     * The tunnel network's subnet (chosen once) and cloudflared's image, before anything changes (exit 3).
     *
     * @return string the subnet
     */
    private function preflight(OperationRun $run): string
    {
        $data = $run->phase('preflight', function (): array {
            $after = $this->stack->target($this->directory->state());

            return [...$this->stack->pull($after), 'tunnel_subnet' => InstanceSettings::of($after)->tunnelSubnet()];
        }, changes: false);
        $subnet = $data['tunnel_subnet'] ?? null;

        return is_string($subnet) ? $subnet : throw new InstanceException('The tunnel preflight chose no subnet.');
    }

    /** From the state as it is now to the same state with the tunnel on the preflight's subnet. */
    private function change(string $subnet): ConfigChange
    {
        $before = $this->directory->state();

        return TunnelStack::change($before, $this->stack->target($before, $subnet));
    }

    /** A failure before the stack changed (the rotation, a signal received during it): nothing to revert; the journal closes. */
    private function notStarted(OperationRun $run, ConfigChange $change, Throwable $failure, bool $admin): CommandResult
    {
        $error = TunnelResults::failure($failure, $change);
        $run->markReverted(['error' => $error['code'], 'stack' => 'unchanged']);

        return TunnelResults::rolledBack($this->directory->state(), $error, $admin, $this->fieldset());
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

        return TunnelResults::rolledBack($this->directory->state(), $error, $admin, $this->fieldset());
    }

    /** The rotation stays whatever became of the tunnel: its passwords are listed once, and something changed. */
    private function withRotation(CommandResult $result, DemoCredentials $rotation, FieldsetContext $context, bool $toFile): CommandResult
    {
        $result = $result->withChanged(true)->withWarning('demo_passwords_rotated', 'The demo accounts still had the password "password": they got new random passwords (listed once under secrets), and their sessions, API tokens, unused sign-in links and password-reset tokens were revoked.');

        return $rotation->apply($result, $context, $toFile);
    }

    private function fieldset(): string
    {
        return $this->directory->root.'/fieldset';
    }
}
