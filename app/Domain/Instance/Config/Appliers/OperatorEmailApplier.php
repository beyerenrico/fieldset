<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigResults;
use App\Domain\Instance\Config\MailOptions;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ConfirmationRequired;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * `fieldset config set operator-email <email> [--revoke-previous]` (R32, plan U11/U12, ADR 0080). Operators live in the
 * app's database, not in the instance state (which keeps personal data only as digests), so nothing is rendered: under
 * the instance lock a socket-less one-off (`instance:operator-email`, KTD25) grants the operator role, creating the
 * account when there is none, and issues its first sign-in link when it has no password yet. The link leaves only
 * through --secrets-file, the browser hand-over or `secrets` (SignInDelivery). `--revoke-previous` revokes every other
 * operator and needs confirmation (exit 6 without --yes and without a terminal). The app side audits
 * `instance.config_changed`.
 *
 * The same address again is `changed: false`: a usable link delivered before is kept, not replaced (warning
 * `sign_in_link_outstanding` with its expiry). Only when none is left does a repeat issue a new one, reported as a
 * change with the warning `sign_in_link_issued` (ADR 0080 amendment 2026-10-19). Like every `config set` key it waits
 * for an unfinished operation to be resumed first (exit 9 `operation_unfinished`); `rate_limited` (exit 9) carries
 * `retry_after`.
 */
final readonly class OperatorEmailApplier
{
    public const string KEY = 'operator-email';

    /** App error codes that are the caller's to fix (exit 2). */
    private const array INPUT_ERRORS = ['invalid_input', 'sso_required', 'inactive'];

    /** Seconds between two owner links of one account (the password broker's throttle, config/auth.php). */
    private const int LINK_THROTTLE_SECONDS = 60;

    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private ContainerIdentity $identity,
        private AppCommands $app,
        private OperationJournal $journal,
    ) {}

    /** @param  (Closure(string): bool)|null  $confirm  asks in a terminal; null when the command may not prompt */
    public function set(ConfigRequest $request, FieldsetContext $context, ?Closure $confirm, bool $toFile): CommandResult
    {
        if ($request->hasAcmeOptions() || $request->credentials instanceof SmtpCredentials || ($request->mail instanceof MailOptions && (! $request->mail->isEmpty() || $request->mail->testTo !== null))) {
            throw new InvalidInput('`config set operator-email` takes only --revoke-previous.', 'invalid_flag', 'Run `fieldset config set operator-email <email>`, with --revoke-previous to remove the other operators.');
        }
        $email = ConfigValues::email($request->value, self::KEY);
        $state = $this->installedState();
        if ($request->revokePrevious && ! $context->yes) {
            $this->confirm($email, $confirm);
        }
        $handle = $this->lock->acquire($this->identity->holder('fieldset', $context->facts->callerUid()), 'config');

        try {
            $open = $this->journal->current();
            if ($open instanceof JournalRecord) {
                throw new OperationUnfinished($open);
            }
            $result = $this->app->operatorEmail(ComposeProject::forInstance($state, $this->directory), $email, $request->revokePrevious, $context->facts->callerUid());
        } finally {
            $this->lock->release($handle);
        }

        return $this->result($result, $email, $context, $toFile);
    }

    private function result(OneOffResult $result, string $email, FieldsetContext $context, bool $toFile): CommandResult
    {
        if (! $result->ok) {
            throw $this->failure($result);
        }
        $revoked = array_values(array_filter(is_array($result->data['revoked'] ?? null) ? $result->data['revoked'] : [], is_string(...)));
        $link = $result->secret(SignInDelivery::SECRET);
        $data = [
            'key' => self::KEY, 'to' => $email, 'created' => ($result->data['created'] ?? false) === true, 'granted' => ($result->data['granted'] ?? false) === true,
            'revoked' => $revoked, 'login_url' => is_string($result->data['login_url'] ?? null) ? $result->data['login_url'] : null,
            'next_commands' => [$this->directory->root.'/fieldset status'],
        ];
        $repeat = ! $data['created'] && ! $data['granted'] && $revoked === [];
        $command = CommandResult::ok(ConfigResults::COMMAND, $data, changed: ! $repeat || $link !== null);
        if ($link === null) {
            return $this->outstanding($command, $result, $email);
        }
        if ($repeat) {
            $command = $command->withWarning('sign_in_link_issued', "{$email} is the operator already and has no password yet, and no usable sign-in link was left: a new one was issued.");
        }

        return SignInDelivery::deliver($command, $context, $link['value'], $link['expires_at'], $toFile, $email);
    }

    /** A usable link delivered before was kept: say until when, and how to get a new one once it is gone. */
    private function outstanding(CommandResult $command, OneOffResult $result, string $email): CommandResult
    {
        if (($result->data['link'] ?? null) !== 'outstanding') {
            return $command;
        }
        $until = is_string($result->data['link_expires_at'] ?? null) ? $result->data['link_expires_at'] : 'it expires';

        return $command->withWarning(
            'sign_in_link_outstanding',
            "{$email} has no password yet; the sign-in link issued earlier stays valid until {$until}, so no new one was issued (it would invalidate that one).",
            "Use that link. If it is lost, run `fieldset config set operator-email {$email}` again after {$until} for a new one.",
        );
    }

    /** @param  (Closure(string): bool)|null  $confirm */
    private function confirm(string $email, ?Closure $confirm): void
    {
        $question = "Make {$email} the platform operator and revoke the operator role of everyone else?";
        if (! $confirm instanceof Closure) {
            throw new ConfirmationRequired($question);
        }
        if (! $confirm($question)) {
            throw new InstanceException('Cancelled; nothing was changed.', ExitCode::AbortedUnchanged, 'cancelled');
        }
    }

    private function failure(OneOffResult $result): InstanceException
    {
        $code = $result->errorCode();

        return match (true) {
            in_array($code, self::INPUT_ERRORS, true) => new InstanceException($result->errorMessage(), ExitCode::InvalidInput, $code, $result->errorHint()),
            $code === 'rate_limited' => new InstanceException($result->errorMessage(), ExitCode::AbortedUnchanged, $code, 'Wait a minute, then run the command again; nothing was changed.', true, ['retry_after' => self::LINK_THROTTLE_SECONDS]),
            default => new InstanceException('The operator could not be set: '.$result->errorMessage(), ExitCode::HealthCheckFailed, $code, 'Check that the instance runs (`fieldset status`, `fieldset logs app`), then run the command again; nothing was changed.', true, ['output' => $result->output]),
        };
    }

    private function installedState(): InstanceState
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fieldset install` first (it resumes an unfinished install).');
        }

        return $state;
    }
}
