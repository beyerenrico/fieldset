<?php

declare(strict_types=1);

namespace App\Domain\Instance\Demo;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\MailSummary;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;

/**
 * `fsa demo` (§7, R29, R30, F2, ADR 0081) on an installed instance, under the instance lock with a journal (R54):
 *
 * - Seeds the demo organization, forms, workflow and users unless they exist (`changed: false` then). Passwords follow
 *   the reachability rule: `password` on a loopback-only local instance (in `data.demo.default_password`), random ones
 *   (one-time secrets `demo_password:<email>`) when it is reachable beyond loopback.
 * - An existing demo whose accounts still have `password` on an instance that is reachable beyond loopback (e.g. after
 *   `config set domain`) has its passwords rotated at once (warning `demo_passwords_rotated`).
 * - Hands out a one-time sign-in link for the demo owner under the delivery policy (SignInDelivery): the browser only
 *   with `--open` or for a person at a desktop terminal, else `secrets` or `--secrets-file`.
 * - `--rotate-passwords` gives every demo account a new random password and revokes its sessions and API tokens.
 */
final readonly class DemoOperation
{
    public const string OPERATION = 'demo';

    public function __construct(
        private InstanceDirectory $directory,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private DemoData $demo,
        private AppCommands $app,
    ) {}

    public function run(FsaContext $context, bool $rotate, bool $secretsToFile): CommandResult
    {
        $state = $this->directory->state();
        if (! InstallRecord::isInstalled($state)) {
            throw new InvalidInput('The instance is not installed yet.', 'not_installed', 'Run `fsa install --demo` with the flags of your install: it resumes an unfinished install and adds the demo data.');
        }
        $project = ComposeProject::forInstance($state, $this->directory);
        $uid = $context->facts->callerUid();

        return $this->supervisor->run(
            self::OPERATION,
            $this->identity->holder('fsa', $uid),
            fn (OperationRun $run): CommandResult => $rotate ? $this->rotation($run, $project, $context, $uid, $secretsToFile) : $this->seeding($run, $project, $context, $uid, $secretsToFile),
            resume: true,
            context: ['rotate' => $rotate],
            progress: $context->progress,
        );
    }

    private function seeding(OperationRun $run, ComposeProject $project, FsaContext $context, int $uid, bool $toFile): CommandResult
    {
        [$credentials, $warnings] = $this->demo->provide($run, $project, $uid, reissue: false);
        $result = CommandResult::ok(self::OPERATION, $this->summary(), changed: $credentials->changed());
        foreach ($warnings as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return $this->signIn($credentials->apply($result, $context, $toFile), $project, $context, $uid, $toFile);
    }

    private function rotation(OperationRun $run, ComposeProject $project, FsaContext $context, int $uid, bool $toFile): CommandResult
    {
        $credentials = $this->demo->rotation($run, $project, $uid);
        $result = CommandResult::ok(self::OPERATION, $this->summary(), changed: $credentials->changed());
        if (! $credentials->changed()) {
            $result = $result->withWarning('no_demo_users', 'There are no demo accounts on this instance; nothing was rotated.', 'Add the demo data with `fsa demo`.');
        }

        return $credentials->apply($result, $context, $toFile);
    }

    /** The demo owner's one-time sign-in link (15 minutes); without one the result still stands, with a warning. */
    private function signIn(CommandResult $result, ComposeProject $project, FsaContext $context, int $uid, bool $toFile): CommandResult
    {
        $issued = $this->app->loginLink($project, DemoCredentials::OWNER, $uid);
        $link = $issued->ok ? $issued->secret(SignInDelivery::SECRET) : null;
        if ($link === null) {
            return $result->withData([SignInDelivery::SECRET => null])->withWarning('sign_in_link_unavailable', 'No sign-in link for '.DemoCredentials::OWNER.' could be issued: '.$issued->errorMessage(), 'Sign in with the demo passwords, or run '.SignInDelivery::loginLinkCommand(DemoCredentials::OWNER).' once the cause is fixed.');
        }

        return SignInDelivery::deliver($result, $context, $link['value'], $link['expires_at'], $toFile, DemoCredentials::OWNER);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $state = $this->directory->state();
        $settings = InstanceSettings::of($state);
        $fsa = $this->directory->root.'/fsa';

        return [
            'url' => $settings->instanceUrl(),
            'mode' => $state->mode(),
            'mail' => MailSummary::of($state),
            'next_commands' => ["{$fsa} status", "{$fsa} login-link ".DemoCredentials::OWNER, "{$fsa} demo --rotate-passwords"],
        ];
    }
}
