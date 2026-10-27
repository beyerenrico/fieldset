<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Backup\BackupRun;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\RestoreOperation;
use App\Domain\Instance\State\InputGrammar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset login-link <email>` (§7, R28, KTD21, ADR 0056): a single-use sign-in link (15 minutes) for an existing
 * admin, issued by the app-side `identity:login-link` in a socket-less one-off (KTD25) and audited with the
 * caller's host UID. The link leaves only through `secrets`, --secrets-file or the browser hand-over (--open, or a
 * person at a desktop terminal). A one-off that prints no result is exit 4 (retryable), like install's one-offs.
 *
 * It takes the instance lock and refuses while any operation is unfinished (exit 9 `operation_unfinished`, U23
 * verification V9): between the runs of a reset or restore it would write a link and an audit entry into a database
 * whose final heads were already taken, or that is about to be replaced. A backup holding the lock (scheduled or `fieldset
 * backup`) changes no data: the journal is then read without the lock and the link issued (M12 gate C11); a backup runs
 * next to the app's own writes anyway.
 */
#[Description('Issue a one-time sign-in link (15 minutes) for an existing admin')]
#[Signature('fieldset:login-link
        {email : E-mail of an existing admin}
        {--open : Open the link in the browser of a desktop session instead of printing it}')]
final class LoginLinkCommand extends FieldsetCommand
{
    /** App error codes that are the caller's to fix (exit 2). */
    private const array INPUT_ERRORS = ['user_not_found', 'not_admin', 'inactive', 'sso_required', 'invalid_input'];

    /** The one-off printed no result (it failed or timed out before the app answered): exit 4, retryable. */
    private const array NO_RESULT = ['one_off_failed', 'one_off_timeout'];

    /** It changes the instance, so it saves the owner the shim measured with it (2.1.1 U4, KTD6). */
    protected function recordsOwner(): bool
    {
        return true;
    }

    protected function opensBrowser(): bool
    {
        return (bool) $this->option('open');
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        $argument = $this->argument('email');
        $email = is_string($argument) ? strtolower(trim($argument)) : '';
        if (! InputGrammar::isEmail($email)) {
            throw new InvalidInput('Pass the e-mail address of an existing admin.', 'invalid_flag');
        }
        $directory = $context->directory();
        $state = RestoreOperation::installed($directory); // an unfinished install or adopt names itself (exit 9, M12 gate C4)
        $lock = $this->laravel->make(InstanceLock::class);
        $handle = $this->lock($lock, $context);

        try {
            $open = $this->laravel->make(OperationJournal::class)->current(); // read under the lock (or next to a backup's)
            if ($open instanceof JournalRecord) {
                throw new OperationUnfinished($open);
            }
            $result = $this->laravel->make(AppCommands::class)->loginLink(ComposeProject::forInstance($state, $directory), $email, $context->facts->callerUid());
        } finally {
            if ($handle instanceof LockHandle) {
                $lock->release($handle);
            }
        }
        $link = $result->ok ? $result->secret(SignInDelivery::SECRET) : null;
        if ($link === null) {
            throw $this->failure($result);
        }
        $data = ['email' => $email, 'kind' => is_string($result->data['kind'] ?? null) ? $result->data['kind'] : 'admin'];

        return SignInDelivery::deliver(CommandResult::ok('login-link', $data, changed: true), $context, $link['value'], $link['expires_at'], is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '', $email);
    }

    /** The instance lock, or null while a backup holds it: a backup changes no data (C11). Any other holder: exit 9. */
    private function lock(InstanceLock $lock, FieldsetContext $context): ?LockHandle
    {
        try {
            return $lock->acquire($this->laravel->make(ContainerIdentity::class)->holder('fieldset', $context->facts->callerUid()), 'login-link');
        } catch (LockHeld $held) {
            if ($held->holder->operation !== BackupRun::OPERATION) {
                throw $held;
            }

            return null;
        }
    }

    private function failure(OneOffResult $result): InstanceException
    {
        $code = $result->errorCode();

        return match (true) {
            in_array($code, self::INPUT_ERRORS, true) => new InstanceException($result->errorMessage(), ExitCode::InvalidInput, $code, $result->errorHint()),
            $code === 'rate_limited' => new InstanceException($result->errorMessage(), ExitCode::AbortedUnchanged, $code, 'Wait a few minutes, then run the command again.', true),
            in_array($code, self::NO_RESULT, true) => new InstanceException('The sign-in link could not be issued: '.$result->errorMessage(), ExitCode::HealthCheckFailed, $code, 'Check that the instance runs (`fieldset status`, `fieldset logs app`), then run the command again.', true, ['output' => $result->output]),
            default => new InstanceException('The sign-in link could not be issued: '.$result->errorMessage(), ExitCode::InternalError, $code, 'Check that the instance runs (`fieldset status`), then run the command again.', true, ['output' => $result->output]),
        };
    }
}
