<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationInterrupted;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Closure;
use Throwable;

/**
 * `fsa config set <key> <value>` (R31, R32, R54, KTD6, KTD19, KTD26, ADR 0077, ADR 0078). For every key:
 *
 * plan (validate, exit 2; the same value twice is `changed: false` without a Docker call) → confirm when the URL
 * changes (the organizations with SSO and the links that follow it; exit 6 without --yes) → under the lock and the
 * journal: snapshot (the previous state) → preflight (exit 3, nothing changed) → apply (workers stopped, render from
 * the target state, `compose up` recreates only what changed) → verify (health, smoke, certificate, the new URL) →
 * workers (queue and scheduler start on the verified settings) → audit `instance.config_changed`.
 *
 * A failed apply, verify or worker start reverts to the snapshot (files and the whole previous stack) and verifies
 * the old URL again: exit 5 `rolled_back`, the journal archived. If the revert fails too, the services that serve
 * requests stop and the result is exit 8 with recovery steps; the journal stays open, and the same command then only
 * finishes the revert. SIGTERM, SIGINT and SIGHUP during apply or verify start the same revert (Interruption).
 * Once the workers started, the change is settled: a run that died after that leaves the journal open, and the same
 * command then only completes and audits it, never applying, verifying or reverting it again (M11 gate).
 *
 * Appliers with SettingHooks add a check to verify (the test mail of `config set mail smtp`) and a completion step
 * after the workers started (the catcher volume, the captured invitations; ADR 0080). The SMTP password is registered
 * with the output redactor first, so no message, warning or log line can carry it.
 */
final readonly class ConfigSetter
{
    public const string OPERATION = 'config';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private ConfigKeys $keys,
        private ConfigRollout $rollout,
        private ConfigResume $resume,
        private ConfigResults $results,
        private UrlImpact $impact,
        private AppCommands $app,
        private Interruption $interruption,
        private DefaultPasswordGuard $passwords,
    ) {}

    /** @param  (Closure(string): bool)|null  $confirm  asks in a terminal; null when the command may not prompt */
    public function set(ConfigRequest $request, FsaContext $context, ?Closure $confirm): CommandResult
    {
        $applier = $this->keys->applier($request->key);
        $this->assertOptions($request);
        foreach ($request->credentials?->secrets() ?? [] as $secret) {
            $context->redactor->register($secret);
        }
        $state = $this->installedState();
        $this->resume->refuseLiveLock();
        $open = $this->resume->unfinished($request);
        $change = $open instanceof JournalRecord ? $this->resume->change($open, $request)?->withCredentials($request->credentials) : null;
        $change ??= $applier->plan(($open instanceof JournalRecord ? $this->resume->previous($open) : null) ?? $state, $request);
        if (! $open instanceof JournalRecord && $change->unchanged()) {
            return $this->results->unchanged($change);
        }
        $revertOnly = $open instanceof JournalRecord && $open->hasStarted('revert');
        $impact = $change->changesUrl() && ! $revertOnly ? $this->confirm($change, $context, $confirm) : null;
        $holder = $this->identity->holder('fsa', $context->facts->callerUid());

        return $this->interruption->guard(fn (): CommandResult => $this->supervisor->run(
            self::OPERATION,
            $holder,
            fn (OperationRun $run): CommandResult => $this->steps($run, $applier, $change, $request, $context, $impact),
            resume: $open instanceof JournalRecord, // never adopt a journal another run opened meanwhile
            context: $request->toContext(),
            progress: $context->progress,
            accepts: static fn (JournalRecord $record): bool => $request->sameAs($record->context()),
        ));
    }

    /**
     * The guard is armed from the start of the steps until the change is verified, and disarmed on every way out, so
     * a late signal can never throw out of the supervisor's own failure handling or journal archiving.
     *
     * @param  array<string, mixed>|null  $impact
     */
    private function steps(OperationRun $run, SettingApplier $applier, ConfigChange $change, ConfigRequest $request, FsaContext $context, ?array $impact): CommandResult
    {
        try {
            return $this->guardedSteps($run, $applier, $change, $request, $context, $impact);
        } finally {
            $this->interruption->disarm();
        }
    }

    /** @param  array<string, mixed>|null  $impact */
    private function guardedSteps(OperationRun $run, SettingApplier $applier, ConfigChange $change, ConfigRequest $request, FsaContext $context, ?array $impact): CommandResult
    {
        $previousRoot = $this->previousRoot($run->record()->phaseData('snapshot'));
        $settled = $run->isResumed() && $run->record()->hasCompleted('workers') && ! $run->record()->hasStarted('revert');

        try {
            $this->interruption->arm();
            $previousRoot = $this->previousRoot($run->phase('snapshot', fn (): array => $this->snapshot($change), changes: false));
            if (! $run->isResumed() && $this->directory->state()->toArray() !== $change->before->toArray()) {
                throw new InstanceException('Another fsa command changed the instance while this one waited.', ExitCode::AbortedUnchanged, 'state_changed', 'Run the command again.', true);
            }
            if ($run->isResumed() && $run->record()->hasStarted('revert')) {
                return $this->finishRevert($run, $change, $request, $previousRoot, $context);
            }
            $change = $this->preflight($run, $applier, $change, $context);
            if ($settled) {
                $this->interruption->disarm(); // as after the workers phase: a signal no longer reverts the change
            } else {
                $this->interruption->checkpoint();
            }
        } catch (OperationInterrupted $interrupted) {
            if (! $run->isResumed() || ! $run->record()->hasChanges() || $settled) {
                throw $interrupted; // nothing changed yet, or the change an earlier run verified stays: exit 9
            }
            $this->interruption->consume(); // an earlier, killed run left its change applied and unverified: revert it

            return $this->revert($run, $change, $request, ConfigFailure::describe($interrupted, $change), $previousRoot, $context);
        }
        if ($settled) { // an earlier run verified the change and started the workers, then died before the audit
            return $this->finish($run, $applier, $change, $request, $context, $impact, $run->record()->phaseData('apply'), $run->record()->phaseData('verify'));
        }

        try {
            $services = $run->phase('apply', fn (): array => $this->rollout->apply($change), always: true, maxSeconds: ConfigRollout::APPLY_SECONDS);
            $this->interruption->checkpoint();
            $checked = $run->phase('verify', fn (): array => $this->verify($applier, $change, $request, $context), changes: false, always: true, maxSeconds: ConfigRollout::verifySeconds($change->after) + ($applier instanceof SettingHooks ? $applier->verifySeconds() : 0));
            $this->interruption->checkpoint();
            $run->phase('workers', fn (): array => $this->rollout->startWorkers($change->after), always: true, maxSeconds: ConfigRollout::WAIT_SECONDS);
            $this->interruption->checkpoint();
            $this->interruption->disarm(); // from here on the verified change stays; a signal no longer reverts it
        } catch (Throwable $failure) {
            $this->interruption->consume(); // first: a signal now must not throw out of this handler

            return $this->revert($run, $change, $request, ConfigFailure::describe($failure, $change), $previousRoot, $context);
        }

        return $this->finish($run, $applier, $change, $request, $context, $impact, $services, $checked);
    }

    /**
     * The settled change: the applier's completion, the audit (once: a resumed run that finds it completed reuses it)
     * and the result. The previous SMTP credentials go last, after the journal's last phase; the supervisor archives
     * the journal right after, and until then a resumed run only repeats this step.
     *
     * @param  array<string, mixed>|null  $impact
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $checked
     */
    private function finish(OperationRun $run, SettingApplier $applier, ConfigChange $change, ConfigRequest $request, FsaContext $context, ?array $impact, array $services, array $checked): CommandResult
    {
        $completed = $this->completed($applier, $change, $request);
        $audit = $run->phase('audit', fn (): array => $this->audit($change, $context), changes: false);
        $this->rollout->discardStash();

        return $this->results->succeeded($change, $services, $checked, $audit, $impact, $completed);
    }

    /** @return array{checked: list<string>} the smoke test of the new settings, then the applier's own check */
    private function verify(SettingApplier $applier, ConfigChange $change, ConfigRequest $request, FsaContext $context): array
    {
        $checked = $this->rollout->verify($change->after, $context->progress);
        if ($applier instanceof SettingHooks) {
            $checked['checked'] = [...$checked['checked'], ...$applier->verify($change, $request, ComposeProject::forInstance($change->after, $this->directory))];
        }

        return $checked;
    }

    /**
     * The change is verified and running: the applier cleans up and reports (again on a resumed run: every step there
     * tolerates being repeated). Nothing here reverts; a failure is a warning.
     *
     * @return array{data: array<string, mixed>, warnings: list<array{code: string, message: string, hint: string|null}>}
     */
    private function completed(SettingApplier $applier, ConfigChange $change, ConfigRequest $request): array
    {
        if (! $applier instanceof SettingHooks) {
            return ['data' => [], 'warnings' => []];
        }

        try {
            return $applier->completed($change, $request, ComposeProject::forInstance($change->after, $this->directory));
        } catch (Throwable $e) {
            return ['data' => [], 'warnings' => [['code' => 'completion_failed', 'message' => "The change was applied, but finishing it failed: {$e->getMessage()}", 'hint' => 'Check `fsa status`; the change itself stays.']]];
        }
    }

    /** @param  array<string, mixed>  $snapshot */
    private function previousRoot(array $snapshot): ?string
    {
        return is_string($snapshot['ca_root'] ?? null) ? $snapshot['ca_root'] : null;
    }

    /** @return array<string, mixed> what a resumed run needs: the previous state and the CA root it trusts */
    private function snapshot(ConfigChange $change): array
    {
        return [
            ...$change->summary(),
            'previous' => $change->before->toArray(),
            'ca_root' => InstanceSettings::of($change->before)->acmeCaRoot() === null ? null : $this->directory->read(AcmeCaRoot::FILE),
        ];
    }

    /**
     * Checks before anything changes (exit 3) and the target they refine (a chosen port, the edge subnet), journaled
     * with the target. First the demo-password rule for a change that widens the instance's reach (DefaultPasswordGuard),
     * then the applier's own checks. A resumed run replays the recorded target instead of checking again: the first
     * attempt may already have moved the instance onto the ports or the network the checks would now find taken.
     */
    private function preflight(OperationRun $run, SettingApplier $applier, ConfigChange $change, FsaContext $context): ConfigChange
    {
        $data = $run->phase('preflight', function () use ($applier, $change, $context): array {
            $this->passwords->check($change->before, $change->after);
            $checked = $applier->preflight($change, $context->facts);

            return [...$this->rollout->pullMissing($checked->after), 'to' => $checked->to, 'after' => $checked->after->toArray(), 'warnings' => array_slice($checked->warnings, count($change->warnings))];
        }, changes: false);
        $after = is_array($data['after'] ?? null) ? InstanceState::fromArray($data['after']) : $change->after;
        $change = $change->withAfter($after, is_string($data['to'] ?? null) ? $data['to'] : null);
        foreach (is_array($data['warnings'] ?? null) ? $data['warnings'] : [] as $warning) {
            if (is_array($warning) && is_string($warning['code'] ?? null) && is_string($warning['message'] ?? null)) {
                $change = $change->withWarning($warning['code'], $warning['message'], is_string($warning['hint'] ?? null) ? $warning['hint'] : null);
            }
        }

        return $change;
    }

    /**
     * Back to the snapshot after a failed apply, verify or worker start. The revert runs to its end whatever signal
     * arrives (it is what makes an interruption safe); the failure it undoes is journaled with it.
     *
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $error
     */
    private function revert(OperationRun $run, ConfigChange $change, ConfigRequest $request, array $error, ?string $previousRoot, FsaContext $context, bool $resumed = false): CommandResult
    {
        $this->interruption->disarm();
        $steps = [];

        try {
            $run->phase('revert', function () use ($change, $previousRoot, $context, &$steps): array {
                $restored = $this->rollout->revert($change, $previousRoot, static function (string $step) use (&$steps): void {
                    $steps[] = $step;
                });

                return [...$restored, ...$this->rollout->verify($change->before, $context->progress, 'revert')];
            }, data: ['failure' => $error['code']], always: true, maxSeconds: ConfigRollout::WAIT_SECONDS + ConfigRollout::verifySeconds($change->before));
        } catch (Throwable $revertFailure) {
            $run->note('revert_failed', ['completed' => $steps]);

            return $this->results->revertFailed($change, $request, $error, ConfigFailure::describe($revertFailure, $change), $steps, $this->rollout->stopFrontends($change->before));
        }
        $run->markReverted(['error' => $error['code']]);

        return $this->results->rolledBack($change, $request, $error, $resumed);
    }

    /**
     * An earlier run started the revert and was cut off, or the revert failed (exit 8): this run only finishes it, from
     * the snapshot, and never repeats the change that failed (ADR 0078).
     */
    private function finishRevert(OperationRun $run, ConfigChange $change, ConfigRequest $request, ?string $previousRoot, FsaContext $context): CommandResult
    {
        $failure = $run->record()->lastAttemptData('revert')['failure'] ?? null;
        $code = is_string($failure) ? $failure : 'revert_failed';
        $error = ['code' => $code, 'message' => 'restoring the previous settings did not finish', 'hint' => ConfigFailure::hint($code, $change), 'data' => []];

        return $this->revert($run, $change, $request, $error, $previousRoot, $context, resumed: true);
    }

    /** @return array{audited: bool, error?: string} a failed audit is a warning: the verified change stays */
    private function audit(ConfigChange $change, FsaContext $context): array
    {
        $summary = $context->redactor->redact($change->summary());
        /** @var array{key: string, from: string|null, to: string|null, url: array{from: string, to: string}} $summary */
        try {
            $result = $this->app->auditConfigChange(ComposeProject::forInstance($change->after, $this->directory), $summary, $context->facts->callerUid());
        } catch (Throwable $e) {
            return ['audited' => false, 'error' => $e->getMessage()];
        }

        return $result->ok ? ['audited' => true] : ['audited' => false, 'error' => $result->errorMessage()];
    }

    /**
     * @return array<string, mixed> the impact (confirmed with --yes, or in a terminal)
     *
     * @throws InstanceException exit 6 without --yes and without a terminal, exit 9 when declined
     */
    private function confirm(ConfigChange $change, FsaContext $context, ?Closure $confirm): array
    {
        $impact = $this->impact->assess(ComposeProject::forInstance($change->before, $this->directory));
        if ($context->yes) {
            return $impact;
        }
        $question = UrlImpact::question($change, $impact);
        if (! $confirm instanceof Closure) {
            throw ConfigResults::confirmationRequired($change, $impact, $question);
        }
        if (! $confirm($question)) {
            throw new InstanceException('Cancelled; nothing was changed.', ExitCode::AbortedUnchanged, 'cancelled');
        }

        return $impact;
    }

    /**
     * The ACME options belong to `domain`; `acme-ca` takes only the CA root file (its value is the directory). The SMTP
     * options belong to `mail`.
     */
    private function assertOptions(ConfigRequest $request): void
    {
        if ($request->key !== 'backup-encryption' && $request->hasKeyFileOptions()) {
            throw new InvalidInput("These options do not apply to `config set {$request->key}`.", 'invalid_flag', '--identity-out, --passphrase-file and --passphrase-stdin belong to `config set backup-encryption on`.');
        }
        if ($request->key !== 'mail' && $request->hasKeyOptions()) {
            throw new InvalidInput("These options do not apply to `config set {$request->key}`.", 'invalid_flag', '--smtp-*, --password-stdin, --password-file, --from-*, --test-to and --remove-catcher belong to `config set mail`, --revoke-previous to `config set operator-email`.');
        }
        $allowed = match ($request->key) {
            'domain' => true,
            'acme-ca' => $request->acmeEmail === null && $request->acmeCa === null,
            default => ! $request->hasAcmeOptions(),
        };
        if (! $allowed) {
            throw new InvalidInput("The ACME options do not apply to `config set {$request->key}`.", 'invalid_flag', '--acme-email, --acme-ca, --acme-staging and --acme-ca-root-file belong to `config set domain`; `config set acme-ca` takes only --acme-ca-root-file.');
        }
    }

    private function installedState(): InstanceState
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fsa install` first (it resumes an unfinished install).');
        }

        return $state;
    }
}
