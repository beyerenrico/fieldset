<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
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

/**
 * `fieldset tunnel start|stop|renew` (§7, E11, R20, R29, R61, R65, AE6; KTD12; ADR 0057, ADR 0078, ADR 0082), each under the
 * instance lock with a journal (operation `tunnel`, R54) and guarded against SIGTERM, SIGINT and SIGHUP (Interruption).
 *
 * start: local mode without the local CA (R61, exit 2); a running tunnel is answered as it is (`changed: false`) → the
 * accounts (one read-only one-off, before the lock and the prompt): another account on `password` exits 3,
 * organizations besides the demo need `--yes` (exit 6) → the journaled steps of TunnelStart (the check again under the
 * lock, preflight, demo passwords, the stack with cloudflared, the hostname, 4 hours in runtime.json; exit 5 after a
 * revert). renew: a new expiry while cloudflared lives; read-only until its one write of runtime.json, so a failed or
 * interrupted renew changed nothing (exit 9) and leaves no journal open; a renew cut off by SIGKILL is finished by the
 * next renew, which keeps the tunnel. stop: the tunnel goes and the URL is local
 * again; it finishes any unfinished tunnel journal (a start whose revert failed, a cut-off stop or renew) whatever the
 * tunnel status says, and runs to its end through a signal (like a revert, it heads for the safe state).
 */
final readonly class TunnelOperation
{
    public const string OPERATION = 'tunnel';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private OperationJournal $journal,
        private TunnelStack $stack,
        private TunnelStatus $status,
        private TunnelStart $starter,
        private AppCommands $app,
        private Interruption $interruption,
    ) {}

    /**
     * @param  bool  $confirmed  --yes, or the install that asked for the tunnel on its command line
     * @param  (Closure(string): bool)|null  $confirm  asks in a terminal; null when the command may not prompt
     */
    public function start(FieldsetContext $context, bool $admin, bool $confirmed, ?Closure $confirm, bool $secretsToFile): CommandResult
    {
        $state = $this->installed();
        TunnelRules::assertCanStart($state);
        $current = $this->status->of($state);
        if ($current['state'] === 'active') {
            return TunnelResults::running($state, $current, $admin);
        }
        $project = ComposeProject::forInstance($state, $this->directory);
        $exposure = ExposureCheck::run($this->app, $project, $context->progress);
        $exposure->refuseOtherDefaults('the quick tunnel');

        return $this->starter->run($context, $project, $admin, $this->confirmOrganizations($exposure, $confirmed, $confirm), $secretsToFile);
    }

    public function renew(FieldsetContext $context): CommandResult
    {
        $state = $this->installed();
        $current = $this->status->of($state);
        if ($current['state'] !== 'active') {
            throw new InvalidInput('No quick tunnel runs on this instance.', 'no_tunnel', 'Start one with `fieldset tunnel start`.', ['tunnel' => $current]);
        }

        return $this->interruption->guard(fn (): CommandResult => $this->supervisor->run(
            self::OPERATION,
            $this->identity->holder('fieldset', $context->facts->callerUid()),
            function (OperationRun $run) use ($state, $current, $context): CommandResult {
                try {
                    $this->interruption->arm();
                    $tunnel = $run->phase('renew', fn (): array => $this->stack->renew($state, $context->progress, $this->interruption->disarm(...)), changes: false, maxSeconds: QuickTunnelHostname::maxSeconds());
                } finally {
                    $this->interruption->disarm();
                }

                return TunnelResults::renewed($state, TunnelResults::tunnel($tunnel), $current);
            },
            resume: true, // only its own cut-off renew: it keeps the live tunnel
            context: ['action' => 'renew'],
            progress: $context->progress,
            accepts: static fn (JournalRecord $record): bool => ($record->context()['action'] ?? null) === 'renew',
        ));
    }

    public function stop(FieldsetContext $context): CommandResult
    {
        $state = $this->installed();
        $current = $this->status->of($state);
        $unfinished = $this->journal->current()?->operation() === self::OPERATION;
        if ($current['state'] === 'none' && ! $unfinished) {
            return TunnelResults::notRunning($state);
        }

        return $this->removal($context, $current, 'stop', resume: true);
    }

    /**
     * The expiry cleanup of the next mutating command (TunnelSweeper): removes an expired or stopped tunnel, or, with
     * `$resume`, finishes an unfinished tunnel journal the same way.
     *
     * @param  array<string, mixed>  $current  TunnelStatus::of()
     */
    public function expire(FieldsetContext $context, array $current, bool $resume): CommandResult
    {
        return $this->removal($context, $current, 'expire', $resume);
    }

    /**
     * Removes the tunnel inside an operation. A resumed run (an unfinished tunnel journal) converges whatever the state
     * says: runtime block cleared, the stack brought up from the state without the tunnel (`up --remove-orphans`),
     * workers started, the tunnel network removed.
     *
     * @param  array<string, mixed>  $current  TunnelStatus::of()
     */
    public function stopSteps(OperationRun $run, array $current): CommandResult
    {
        $state = $this->directory->state();
        $remove = $run->isResumed() || InstanceSettings::of($state)->tunnel() || $current['hostname'] !== null;
        $services = $remove
            ? $run->phase('remove', fn (): array => $this->stack->remove($state), always: true, maxSeconds: 2 * TunnelRules::STACK_SECONDS)
            : [];

        return TunnelResults::stopped($this->directory->state(), $current, $services);
    }

    /**
     * The guard stays disarmed: a signal is only recorded and the removal runs to its end, as a `config set` revert does
     * (it heads for the safe state; a cut-off one would leave the journal open).
     *
     * @param  array<string, mixed>  $current
     */
    private function removal(FieldsetContext $context, array $current, string $action, bool $resume): CommandResult
    {
        return $this->interruption->guard(function () use ($context, $current, $action, $resume): CommandResult {
            $this->interruption->checkpoint(); // a signal from earlier in this command stops it before anything starts

            return $this->supervisor->run(
                self::OPERATION,
                $this->identity->holder('fieldset', $context->facts->callerUid()),
                fn (OperationRun $run): CommandResult => $this->stopSteps($run, $current),
                resume: $resume,
                context: ['action' => $action],
                progress: $context->progress,
            );
        });
    }

    /**
     * Organizations besides the demo become reachable from the internet (R65): `--yes`, or a person confirms.
     *
     * @return bool whether exposing organizations was confirmed (false when there was nothing to confirm)
     */
    private function confirmOrganizations(ExposureCheck $exposure, bool $confirmed, ?Closure $confirm): bool
    {
        if ($confirmed || $exposure->nonDemoOrganizations === 0) {
            return $confirmed;
        }
        $question = TunnelRules::exposureQuestion($exposure->nonDemoOrganizations);
        if (! $confirm instanceof Closure) {
            throw new ConfirmationRequired($question);
        }
        if (! $confirm($question)) {
            throw new InstanceException('Cancelled; nothing was changed.', ExitCode::AbortedUnchanged, 'cancelled');
        }

        return true;
    }

    private function installed(): InstanceState
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fieldset install` first (it resumes an unfinished install).');
        }

        return $state;
    }
}
