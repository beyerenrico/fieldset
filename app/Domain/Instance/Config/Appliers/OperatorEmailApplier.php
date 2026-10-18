<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigResults;
use App\Domain\Instance\Config\MailOptions;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ConfirmationRequired;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * `fsa config set operator-email <email> [--revoke-previous]` (R32, plan U11/U12, ADR 0080). Operators live in the
 * app's database, not in the instance state (which keeps personal data only as digests), so nothing is rendered: under
 * the instance lock a socket-less one-off (`instance:operator-email`, KTD25) grants the operator role, creating the
 * account when there is none, and issues its first sign-in link when it has no password yet. The link leaves only
 * through --secrets-file, the browser hand-over or `secrets` (SignInDelivery). `--revoke-previous` revokes every other
 * operator and needs confirmation (exit 6 without --yes and without a terminal). The same address again is
 * `changed: false`. The app side audits `instance.config_changed`.
 */
final readonly class OperatorEmailApplier
{
    public const string KEY = 'operator-email';

    /** App error codes that are the caller's to fix (exit 2). */
    private const array INPUT_ERRORS = ['invalid_input', 'sso_required', 'inactive'];

    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private ContainerIdentity $identity,
        private AppCommands $app,
    ) {}

    /** @param  (Closure(string): bool)|null  $confirm  asks in a terminal; null when the command may not prompt */
    public function set(ConfigRequest $request, FsaContext $context, ?Closure $confirm, bool $toFile): CommandResult
    {
        if ($request->hasAcmeOptions() || $request->credentials instanceof SmtpCredentials || ($request->mail instanceof MailOptions && (! $request->mail->isEmpty() || $request->mail->testTo !== null))) {
            throw new InvalidInput('`config set operator-email` takes only --revoke-previous.', 'invalid_flag', 'Run `fsa config set operator-email <email>`, with --revoke-previous to remove the other operators.');
        }
        $email = ConfigValues::email($request->value, self::KEY);
        $state = $this->installedState();
        if ($request->revokePrevious && ! $context->yes) {
            $this->confirm($email, $confirm);
        }
        $handle = $this->lock->acquire($this->identity->holder('fsa', $context->facts->callerUid()), 'config');

        try {
            $result = $this->app->operatorEmail(ComposeProject::forInstance($state, $this->directory), $email, $request->revokePrevious, $context->facts->callerUid());
        } finally {
            $this->lock->release($handle);
        }

        return $this->result($result, $email, $context, $toFile);
    }

    private function result(OneOffResult $result, string $email, FsaContext $context, bool $toFile): CommandResult
    {
        if (! $result->ok) {
            throw $this->failure($result);
        }
        $revoked = array_values(array_filter(is_array($result->data['revoked'] ?? null) ? $result->data['revoked'] : [], is_string(...)));
        $link = $result->secret(SignInDelivery::SECRET);
        $data = [
            'key' => self::KEY, 'to' => $email, 'created' => ($result->data['created'] ?? false) === true, 'granted' => ($result->data['granted'] ?? false) === true,
            'revoked' => $revoked, 'login_url' => is_string($result->data['login_url'] ?? null) ? $result->data['login_url'] : null,
            'next_commands' => [$this->directory->root.'/fsa status'],
        ];
        $changed = $data['created'] || $data['granted'] || $revoked !== [] || $link !== null;
        $command = CommandResult::ok(ConfigResults::COMMAND, $data, changed: $changed);
        if ($link === null) {
            return $command;
        }

        return SignInDelivery::deliver($command, $context, $link['value'], $link['expires_at'], $toFile, $email);
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
            $code === 'rate_limited' => new InstanceException($result->errorMessage(), ExitCode::AbortedUnchanged, $code, 'Wait a minute, then run the command again; nothing was changed.', true),
            default => new InstanceException('The operator could not be set: '.$result->errorMessage(), ExitCode::HealthCheckFailed, $code, 'Check that the instance runs (`fsa status`, `fsa logs app`), then run the command again; nothing was changed.', true, ['output' => $result->output]),
        };
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
