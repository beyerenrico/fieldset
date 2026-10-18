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
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\OperationUnfinished;
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
 * `fsa config set <key> <value>` (R31, R32, R54, KTD6, KTD26, ADR 0077). For every key:
 *
 * plan (validate, exit 2; the same value twice is `changed: false` without a Docker call) → confirm when the URL
 * changes (the organizations with SSO and the links that follow it; exit 6 without --yes) → under the lock and the
 * journal: snapshot (the previous state) → preflight (exit 3, nothing changed) → apply (render from the target state,
 * `compose up` recreates only what changed) → verify (health, smoke, certificate, the new URL) → audit
 * `instance.config_changed` in a socket-less one-off.
 *
 * A failed apply or verify reverts to the snapshot (files and services) and verifies the old URL again: exit 4 with
 * `reverted: true`, the journal archived. If the revert fails too, the instance is stopped and the result is exit 8
 * with recovery steps; the journal stays open and the same command resumes from the snapshot.
 */
final readonly class ConfigSetter
{
    public const string OPERATION = 'config';

    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private InstanceLock $lock,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private ConfigKeys $keys,
        private ConfigRollout $rollout,
        private UrlImpact $impact,
        private AppCommands $app,
    ) {}

    /** @param  (Closure(string): bool)|null  $confirm  asks in a terminal; null when the command may not prompt */
    public function set(ConfigRequest $request, FsaContext $context, ?Closure $confirm): CommandResult
    {
        $applier = $this->keys->applier($request->key);
        $this->assertOptions($request);
        $state = $this->installedState();
        $open = $this->unfinished($request);
        $change = $applier->plan($open['previous'] ?? $state, $request);
        if (! $open['resume'] && $change->unchanged()) {
            return CommandResult::ok(self::OPERATION, [...$this->data($change, $change->before), 'reverted' => false], changed: false);
        }
        $impact = $change->changesUrl() ? $this->confirm($change, $context, $confirm) : null;
        $holder = $this->identity->holder('fsa', $context->facts->callerUid());

        return $this->supervisor->run(
            self::OPERATION,
            $holder,
            fn (OperationRun $run): CommandResult => $this->steps($run, $applier, $change, $request, $context, $impact),
            resume: true,
            context: $request->toContext(),
            progress: $context->progress,
        );
    }

    /** @param  array<string, mixed>|null  $impact */
    private function steps(OperationRun $run, SettingApplier $applier, ConfigChange $change, ConfigRequest $request, FsaContext $context, ?array $impact): CommandResult
    {
        $snapshot = $run->phase('snapshot', fn (): array => $this->snapshot($change), changes: false);
        if (! $run->isResumed() && $this->directory->state()->toArray() !== $change->before->toArray()) {
            throw new InstanceException('Another fsa command changed the instance while this one waited.', ExitCode::AbortedUnchanged, 'state_changed', 'Run the command again.', true);
        }
        $change = $this->preflight($run, $applier, $change, $context);
        $previousRoot = is_string($snapshot['ca_root'] ?? null) ? $snapshot['ca_root'] : null;

        try {
            $services = $run->phase('apply', fn (): array => $this->rollout->apply($change), always: true);
            $checked = $run->phase('verify', fn (): array => $this->rollout->verify($change->after), changes: false, always: true);
        } catch (Throwable $failure) {
            return $this->revert($run, $change, $request, $failure, $previousRoot);
        }
        $audit = $run->phase('audit', fn (): array => $this->audit($change, $context), changes: false, always: true);

        return $this->succeeded($change, $services, $checked, $audit, $impact);
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
     * with the target. A resumed run replays the recorded target instead of checking again: the first attempt may
     * already have moved the instance onto the ports or the network the checks would now find taken.
     */
    private function preflight(OperationRun $run, SettingApplier $applier, ConfigChange $change, FsaContext $context): ConfigChange
    {
        $data = $run->phase('preflight', function () use ($applier, $change, $context): array {
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

    private function revert(OperationRun $run, ConfigChange $change, ConfigRequest $request, Throwable $failure, ?string $previousRoot): CommandResult
    {
        $error = ConfigFailure::describe($failure, $change);

        try {
            $run->phase('revert', fn (): array => [...$this->rollout->revert($change, $previousRoot), ...$this->rollout->verify($change->before)], always: true);
        } catch (Throwable $revertFailure) {
            return $this->revertFailed($change, $request, $error, ConfigFailure::describe($revertFailure, $change));
        }
        $run->markReverted(['error' => $error['code']]);

        return CommandResult::failed(
            self::OPERATION,
            ExitCode::HealthCheckFailed,
            $error['code'],
            "Changing {$change->key} failed, so the previous settings were restored: {$error['message']}",
            $error['hint'],
            true,
            [...$this->data($change, $change->before), 'reverted' => true, 'attempted' => ['url' => $change->newUrl(), 'mode' => $change->after->mode()], 'failure' => $error['data']],
        );
    }

    /**
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $error
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $revertError
     */
    private function revertFailed(ConfigChange $change, ConfigRequest $request, array $error, array $revertError): CommandResult
    {
        $stopped = $this->rollout->stop($change->before);
        $recovery = [
            'The previous settings are back in state.json and the files rendered from it'.($stopped ? '; the instance is stopped, so nothing runs half-configured.' : '; stopping the instance failed as well, check `fsa status`.'),
            'Find the cause in the errors above and in `fsa logs <service>` (a port taken by another program, a full disk, the Docker engine) and fix it.',
            'Then run `'.$request->commandLine().'` again: it starts over from the previous settings and reverts again if the change still fails.',
        ];

        return CommandResult::failed(
            self::OPERATION,
            ExitCode::RollbackFailed,
            'revert_failed',
            "Changing {$change->key} failed ({$error['message']}) and restoring the previous settings failed too: {$revertError['message']}",
            'Follow the recovery steps in data.recovery.',
            false,
            [...$this->data($change, $change->before), 'reverted' => false, 'stopped' => $stopped, 'failure' => [...$error['data'], 'code' => $error['code']], 'revert_failure' => [...$revertError['data'], 'code' => $revertError['code']], 'recovery' => $recovery],
            changed: true,
        );
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
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $checked
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>|null  $impact
     */
    private function succeeded(ConfigChange $change, array $services, array $checked, array $audit, ?array $impact): CommandResult
    {
        $result = CommandResult::ok(self::OPERATION, [
            ...$this->data($change, $change->after), 'reverted' => false,
            'previous' => ['url' => $change->oldUrl(), 'mode' => $change->before->mode()],
            'services' => $services, 'checked' => $checked['checked'] ?? [], 'impact' => $impact, 'audited' => $audit['audited'] ?? false,
        ], changed: true);
        foreach ($change->warnings as $warning) {
            $result = $result->withWarning($warning['code'], $warning['message'], $warning['hint']);
        }
        foreach (ConfigFailure::impactWarnings($change, $impact) as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return ($audit['audited'] ?? false) === true ? $result : $result->withWarning('audit_failed', 'The change was applied, but its audit entry could not be written: '.($audit['error'] ?? 'unknown error').'.', 'The state history (state.json) records the change; check `fsa logs app`.');
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
            throw new InstanceException("Confirmation required: {$question}", ExitCode::ConfirmationRequired, 'confirmation_required', 'Re-run with --yes to confirm.', false, [...$change->summary(), 'impact' => $impact]);
        }
        if (! $confirm($question)) {
            throw new InstanceException('Cancelled; nothing was changed.', ExitCode::AbortedUnchanged, 'cancelled');
        }

        return $impact;
    }

    /** The ACME options belong to `domain`; `acme-ca` takes only the CA root file (its value is the directory). */
    private function assertOptions(ConfigRequest $request): void
    {
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

    /**
     * An unfinished operation (R54): another one blocks (exit 9); an unfinished `config set` resumes only with the
     * same key and value, from the state it recorded before changing anything.
     *
     * @return array{resume: bool, previous?: InstanceState}
     */
    private function unfinished(ConfigRequest $request): array
    {
        $current = $this->journal->current();
        if (! $current instanceof JournalRecord) {
            return ['resume' => false];
        }
        $holder = $this->lock->current();
        if ($holder instanceof LockRecord && ! $this->lock->isStale($holder)) {
            throw new LockHeld($holder);
        }
        if ($current->operation() !== self::OPERATION) {
            throw new OperationUnfinished($current);
        }
        if (! $request->sameAs($current->context())) {
            $recorded = $current->context();
            $key = is_string($recorded['key'] ?? null) ? $recorded['key'] : '?';
            $value = is_string($recorded['value'] ?? null) ? $recorded['value'] : '?';
            throw new InstanceException(
                "An earlier `fsa config set {$key}` did not finish (last phase: ".($current->lastStartedPhase() ?? 'none').').',
                ExitCode::AbortedUnchanged,
                'operation_unfinished',
                "Run `fsa config set {$key} {$value}` (with the same options and --yes) again: it starts over from the settings before that change and reverts if it still fails.",
                false,
                ['operation' => self::OPERATION, 'key' => $key, 'value' => $value, 'last_phase' => $current->lastStartedPhase(), 'journal' => $current->id()],
            );
        }
        $previous = $current->phaseData('snapshot')['previous'] ?? null;

        return is_array($previous) ? ['resume' => true, 'previous' => InstanceState::fromArray($previous)] : ['resume' => true];
    }

    /** @return array<string, mixed> */
    private function data(ConfigChange $change, InstanceState $effective): array
    {
        $effectiveUrl = $effective === $change->after ? $change->newUrl() : $change->oldUrl();

        return ['key' => $change->key, 'from' => $change->from, 'to' => $change->to, 'url' => $effectiveUrl, 'mode' => $effective->mode()];
    }
}
