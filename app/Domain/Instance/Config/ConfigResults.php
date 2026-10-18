<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Install\AcmeStaging;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The envelopes of `fsa config set` (ADR 0077, ADR 0078). One shape for agents: `data.url` and `data.mode` are always
 * the effective values as strings (the change itself is `data.change` where it is not applied yet), `data.command`
 * and `data.options` name the exact command to repeat, `data.next_commands` what to run next. A reverted change is
 * `rolled_back` (exit 5) with `retry_after` or `fix_first`; a failed revert (exit 8) says which of its steps finished.
 */
final readonly class ConfigResults
{
    public const string COMMAND = 'config';

    public function __construct(private InstanceDirectory $directory) {}

    /** @return array<string, mixed> key, values, and the URL and mode in effect */
    public static function data(ConfigChange $change, InstanceState $effective): array
    {
        return ['key' => $change->key, 'from' => $change->from, 'to' => $change->to, 'url' => InstanceSettings::of($effective)->instanceUrl(), 'mode' => $effective->mode()];
    }

    public function unchanged(ConfigChange $change): CommandResult
    {
        return CommandResult::ok(self::COMMAND, [...self::data($change, $change->before), 'reverted' => false], changed: false);
    }

    /** @param  array<string, mixed>  $impact */
    public static function confirmationRequired(ConfigChange $change, array $impact, string $question): InstanceException
    {
        return new InstanceException("Confirmation required: {$question}", ExitCode::ConfirmationRequired, 'confirmation_required', 'Re-run with --yes to confirm.', false, [...self::data($change, $change->before), 'change' => $change->summary(), 'impact' => $impact]);
    }

    /**
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $checked
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>|null  $impact
     */
    public function succeeded(ConfigChange $change, array $services, array $checked, array $audit, ?array $impact): CommandResult
    {
        $result = CommandResult::ok(self::COMMAND, [
            ...self::data($change, $change->after), 'reverted' => false,
            'previous' => ['url' => $change->oldUrl(), 'mode' => $change->before->mode()],
            'services' => $services, 'checked' => $checked['checked'] ?? [], 'impact' => $impact, 'audited' => $audit['audited'] ?? false,
            'next_commands' => $this->nextCommands($change),
        ], changed: true);
        foreach ($change->warnings as $warning) {
            $result = $result->withWarning($warning['code'], $warning['message'], $warning['hint']);
        }
        foreach ([...ConfigFailure::impactWarnings($change, $impact), ...array_filter([AcmeStaging::warning($change->after, $this->fsa())])] as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return ($audit['audited'] ?? false) === true ? $result : $result->withWarning('audit_failed', 'The change was applied, but its audit entry could not be written: '.($audit['error'] ?? 'unknown error').'.', 'The state history (state.json) records the change; check `fsa logs app`.');
    }

    /**
     * The change failed and the previous settings run again (exit 5, `rolled_back`): in this run, or, with
     * `$resumed`, by a run that finished the revert an earlier, cut-off run had started.
     *
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $error
     */
    public function rolledBack(ConfigChange $change, ConfigRequest $request, array $error, bool $resumed = false): CommandResult
    {
        $retry = ConfigFailure::retry($error['code'], $error['hint']);
        $retryable = $retry['retryable'];
        unset($retry['retryable']);
        $message = $resumed
            ? "An earlier `{$request->commandLine()}` failed ({$error['code']}) and was cut off while restoring the previous settings; this run finished restoring them."
            : "Changing {$change->key} failed, so the previous settings were restored: {$error['message']}";

        return CommandResult::failed(self::COMMAND, ExitCode::RolledBack, $error['code'], $message, $error['hint'], $retryable, [
            ...self::data($change, $change->before), 'reverted' => true, 'resumed_revert' => $resumed,
            'attempted' => ['url' => $change->newUrl(), 'mode' => $change->after->mode()], 'failure' => $error['data'], ...$retry,
            'command' => $request->commandLine(), 'options' => $request->options(), 'next_commands' => [$this->fsa().' status'],
        ]);
    }

    /**
     * The revert failed too (exit 8). The recovery text follows the revert steps that completed: only `files` means
     * state.json is the previous one again; none means it may still hold the attempted change.
     *
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $error
     * @param  array{code: string, message: string, hint: string, data: array<string, mixed>}  $revertError
     * @param  list<string>  $steps  completed revert steps (`files`, `services`)
     * @param  list<string>|null  $stopped  services stopped afterwards, null when stopping failed
     */
    public function revertFailed(ConfigChange $change, ConfigRequest $request, array $error, array $revertError, array $steps, ?array $stopped): CommandResult
    {
        $restored = in_array('files', $steps, true);
        $where = match (true) {
            in_array('services', $steps, true) => 'The previous settings are back in state.json and the files rendered from it and their services were started, but checking them failed',
            $restored => 'The previous settings are back in state.json and the files rendered from it, but their services did not come up',
            default => "Writing the previous settings back failed: state.json and the files rendered from it may still hold the attempted change ({$change->key} {$change->to})",
        };
        $recovery = [
            $where.($stopped !== null ? '; '.implode(', ', $stopped).' are stopped, so nothing serves requests half-configured (Postgres and Valkey keep running).' : '; stopping the services failed as well, check `fsa status`.'),
            'Find the cause in the errors above and in `fsa logs <service>` (a port taken by another program, a full disk, the Docker engine) and fix it.',
            'Then run `'.$request->commandLine().'` again: it finishes restoring the previous settings from the snapshot in the journal and does not repeat the change; run it once more afterwards to try the change again.',
        ];

        return CommandResult::failed(
            self::COMMAND,
            ExitCode::RollbackFailed,
            'revert_failed',
            "Changing {$change->key} failed ({$error['message']}) and restoring the previous settings failed too: {$revertError['message']}",
            'Follow the recovery steps in data.recovery.',
            false,
            [
                ...self::data($change, $restored ? $change->before : $change->after), 'reverted' => false, 'stopped' => $stopped !== null, 'stopped_services' => $stopped ?? [],
                'revert_steps' => $steps, 'failure' => [...$error['data'], 'code' => $error['code']], 'revert_failure' => [...$revertError['data'], 'code' => $revertError['code']],
                'recovery' => $recovery, 'command' => $request->commandLine(), 'options' => $request->options(),
            ],
            changed: true,
        );
    }

    /**
     * What to run after a change: switching staging to production, a fresh sign-in link after a URL change (links
     * already sent keep the old address), and the status.
     *
     * @return list<string>
     */
    private function nextCommands(ConfigChange $change): array
    {
        $fsa = $this->fsa();

        return [
            ...(AcmeStaging::active($change->after) ? [AcmeStaging::command($fsa)] : []),
            ...($change->changesUrl() ? ["{$fsa} login-link <owner-email>"] : []),
            "{$fsa} status",
        ];
    }

    /** The instance's launcher, as `fsa install` names it in its next commands (fsa may not be on the PATH). */
    private function fsa(): string
    {
        return $this->directory->root.'/fsa';
    }
}
