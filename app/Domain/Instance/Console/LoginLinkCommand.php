<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InputGrammar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa login-link <email>` (§7, R28, KTD21, ADR 0056): a single-use sign-in link (15 minutes) for an existing
 * admin, issued by the app-side `identity:login-link` in a socket-less one-off (KTD25) and audited with the
 * caller's host UID. The link leaves only through `secrets`, --secrets-file or the browser hand-over.
 */
#[Description('Issue a one-time sign-in link (15 minutes) for an existing admin')]
#[Signature('fsa:login-link {email : E-mail of an existing admin}')]
final class LoginLinkCommand extends FsaCommand
{
    /** App error codes that are the caller's to fix (exit 2). */
    private const array INPUT_ERRORS = ['user_not_found', 'not_admin', 'inactive', 'sso_required', 'invalid_input'];

    protected function perform(FsaContext $context): CommandResult
    {
        $argument = $this->argument('email');
        $email = is_string($argument) ? strtolower(trim($argument)) : '';
        if (! InputGrammar::isEmail($email)) {
            throw new InvalidInput('Pass the e-mail address of an existing admin.', 'invalid_flag');
        }
        $directory = $context->directory();
        $state = $directory->state();
        if (! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fsa install` (it resumes an unfinished install and issues the owner link).');
        }
        $result = $this->laravel->make(AppCommands::class)->loginLink(ComposeProject::forInstance($state, $directory), $email, $context->facts->callerUid());
        $link = $result->ok ? $result->secret(SignInDelivery::SECRET) : null;
        if ($link === null) {
            throw $this->failure($result);
        }
        $data = ['email' => $email, 'kind' => is_string($result->data['kind'] ?? null) ? $result->data['kind'] : 'admin'];

        return SignInDelivery::deliver(CommandResult::ok('login-link', $data, changed: true), $context, $link['value'], $link['expires_at'], is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '');
    }

    private function failure(OneOffResult $result): InstanceException
    {
        $code = $result->errorCode();

        return match (true) {
            in_array($code, self::INPUT_ERRORS, true) => new InstanceException($result->errorMessage(), ExitCode::InvalidInput, $code, $result->errorHint()),
            $code === 'rate_limited' => new InstanceException($result->errorMessage(), ExitCode::AbortedUnchanged, $code, 'Wait a few minutes, then run the command again.', true),
            default => new InstanceException('The sign-in link could not be issued: '.$result->errorMessage(), ExitCode::InternalError, $code, 'Check that the instance runs (`fsa status`), then run the command again.', true, ['output' => $result->output]),
        };
    }
}
