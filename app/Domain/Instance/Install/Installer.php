<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Demo\DemoCredentials;
use App\Domain\Instance\Demo\DemoData;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\MailSummary;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Lifecycle\SignInDelivery;
use App\Domain\Instance\LocalCa\LocalCaInfo;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Operations\Supervisor;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Carbon\CarbonImmutable;

/**
 * `fsa install` (F1, F2, R9–R16, R54, R63, R69, ADR 0072, ADR 0075) in local mode or, with --domain, in domain
 * mode (the edge with automatic HTTPS; the doctor checks DNS and ports 80/443 first, and the smoke test waits for the
 * certificate). Under the instance lock, with every phase journaled before it starts:
 *
 * doctor (verify the release, preflight, choose ports, refuse foreign projects) → secrets → state (and the
 * rendered files) → pull → up (Postgres and Valkey) → migrate (one-off) → app_install (one-off: organization,
 * owner, operator role, owner link) → start (the whole stack, `up --wait`) → [demo (one-off: demo data, R29, ADR 0081)]
 * → smoke (HTTP, a PDF render and a test mail: InstallSmoke) → done. With --smtp-host the instance starts in SMTP mode
 * without the catcher; the SMTP account reaches only the env files of the state phase (InstallMail).
 *
 * A failure in the doctor phase changes nothing (exit 3/7/10, journal archived). A later failure leaves the
 * journal open (exit 4 for health, 3 for pulls): the same command resumes after the last completed phase with the
 * secrets already written, and accepts corrected flags only for phases that have not run. On a finished
 * instance the same flags change nothing (`changed: false`, no Docker call); different ones exit 2.
 */
final readonly class Installer
{
    public const string OPERATION = 'install';

    /** Services the migrations need; the app roles start only once the schema exists. */
    public const array INFRASTRUCTURE = ['postgres', 'redis'];

    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private Supervisor $supervisor,
        private ContainerIdentity $identity,
        private InstallPreflight $preflight,
        private InstanceStack $stack,
        private AppCommands $app,
        private InstallSmoke $smoke,
        private ProxySetup $proxy,
        private DemoData $demo,
    ) {}

    public function situation(): InstallSituation
    {
        if ($this->journal->current()?->operation() === self::OPERATION) {
            return InstallSituation::Resume;
        }
        $state = $this->directory->hasState() ? $this->directory->state() : null;

        return $state instanceof InstanceState && InstallRecord::isInstalled($state) ? InstallSituation::Finished : InstallSituation::Fresh;
    }

    public function install(InstallRequest $request, FsaContext $context, bool $secretsToFile): CommandResult
    {
        if ($this->situation() === InstallSituation::Finished) {
            return $this->unchanged($request);
        }
        $request = $this->effective($request);
        $request->assertConsistent();
        $this->assertModeFlags($request);
        $holder = $this->identity->holder('fsa', $context->facts->callerUid());

        return $this->supervisor->run(
            self::OPERATION,
            $holder,
            fn (OperationRun $run): CommandResult => $this->steps($run, $request, $context, $secretsToFile),
            resume: true,
            context: $request->toContext(),
            progress: $context->progress,
            archive: $this->withoutPersonalInputs(...),
        );
    }

    /**
     * The request an install works with: on a resume the earlier run's inputs under the flags given now (exit 2 for a
     * flag whose phase already applied another value), so `--phone-https tunnel` or `--demo` given once still apply.
     */
    public function effective(InstallRequest $request): InstallRequest
    {
        $current = $this->journal->current();

        return $current instanceof JournalRecord && $current->operation() === self::OPERATION ? $this->resumable($request, $current) : $request;
    }

    /**
     * The archived journal keeps digests of the owner's e-mail and name, never the values (before the secrets
     * phase there is no key yet and they are dropped). The open journal needs them to resume.
     */
    private function withoutPersonalInputs(JournalRecord $record): JournalRecord
    {
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));

        return $record->withValuesReplaced(InstallRecord::PERSONAL, static fn (string $value): ?string => $digest?->digest($value));
    }

    private function steps(OperationRun $run, InstallRequest $request, FsaContext $context, bool $secretsToFile): CommandResult
    {
        if ($run->record()->phases() === [] && $this->directory->hasState() && InstallRecord::isInstalled($this->directory->state())) {
            return $this->unchanged($request); // another install finished while this one waited for the lock
        }
        if ($run->record()->phases() !== []) {
            $run->note('flags', $request->toContext());
        }
        $preflight = $run->phase('doctor', fn (): array => $this->preflight->run($request, $context->facts), changes: false);
        $run->phase('secrets', fn (): array => $this->stack->ensureSecrets($context->redactor));
        $run->phase('state', function () use ($preflight, $context, $request): array {
            $this->writeAcmeCaRoot($request);

            return $this->stack->writeState($preflight, $context->facts, $request->smtpCredentials());
        });
        $state = $this->directory->state();
        $project = ComposeProject::forInstance($state, $this->directory);
        $run->phase('pull', fn (): array => $this->stack->pull(), changes: false);
        $run->phase('up', fn (): array => [...$this->stack->up($project, self::INFRASTRUCTURE), ...$this->stack->trustGateway($project, $preflight)]);
        $run->phase('migrate', fn (): array => $this->migrate($project));
        $link = null;
        $installed = $run->phase('app_install', function () use ($project, $request, $context, &$link): array {
            [$data, $link] = $this->appInstall($project, $request, $context);

            return $data;
        });
        $run->phase('start', fn (): array => $this->stack->up($project, []), maxSeconds: InstanceStack::WAIT_SECONDS);
        // The demo phases run again on a resume (idempotent); passwords an interrupted attempt never showed are reissued.
        [$demo, $demoWarnings] = $request->demo() ? $this->demo->provide($run, $project, $context->facts->callerUid(), reissue: true) : [null, []];
        $run->phase('smoke', fn (): array => $this->smoke->run($state, $project, $request->ownerEmail(), $context->progress), changes: false, maxSeconds: InstallSmoke::maxSeconds($state));
        $warnings = ($installed['already_installed'] ?? false) === true
            ? [['app_already_installed', 'The database already held an organization: --org, --owner-email, --owner-name and --locale were not applied.', 'Change them in the admin panel; the sign-in link goes to the owner given here.'], ...$demoWarnings]
            : $demoWarnings;
        $applied = ($installed['already_installed'] ?? false) !== true;
        $run->phase('done', function () use ($project, $request, $context, $applied, &$link, &$warnings): array {
            [$link, $more] = $this->finish($project, $request, $context, $link, $applied);
            $warnings = [...$warnings, ...$more];

            return ['link_issued' => $link !== null];
        });

        $result = $this->result($this->directory->state(), $request, $preflight, $warnings, $link, $context, $secretsToFile);

        return $demo instanceof DemoCredentials ? $demo->apply($result, $context, $secretsToFile) : $result;
    }

    /** @return array{migrated: true} */
    private function migrate(ComposeProject $project): array
    {
        $outcome = $this->app->migrate($project);
        if (! $outcome->successful()) {
            $output = implode("\n", array_slice(preg_split('/\R/', trim($outcome->stdout)) ?: [], -10));
            throw new InstanceException('The database migrations failed.', ExitCode::HealthCheckFailed, 'migrate_failed', 'Read the output, fix the cause, then run `fsa install` again with the same flags: it resumes here.', true, ['output' => trim($output."\n".$outcome->stderrTail(10))]);
        }

        return ['migrated' => true];
    }

    /** @return array{0: array<string, mixed>, 1: array{value: string, expires_at: CarbonImmutable|null}|null} */
    private function appInstall(ComposeProject $project, InstallRequest $request, FsaContext $context): array
    {
        $result = $this->app->install($project, $request, $context->facts->callerUid());
        if (! $result->ok) {
            throw $this->oneOffFailure('app:install', $result, $result->errorCode() === 'invalid_input');
        }
        $link = $result->secret(SignInDelivery::SECRET);
        if ($link !== null) {
            $context->redactor->register($link['value']);
        }
        $owner = is_array($result->data['owner'] ?? null) ? $result->data['owner'] : [];

        return [[
            'already_installed' => $result->hasWarning('already_installed'),
            'organization' => is_array($result->data['organization'] ?? null) ? ($result->data['organization']['name'] ?? null) : null,
            'operator' => ($owner['operator'] ?? null) === true,
        ], $link];
    }

    /**
     * Marks the instance installed and makes sure a sign-in link goes out: the owner link of this run, or a fresh
     * 15-minute link when app:install ran in an earlier attempt (its link went unused with that failure).
     *
     * @param  array{value: string, expires_at: CarbonImmutable|null}|null  $link
     * @param  bool  $applied  whether app:install applied this run's app flags (not `already_installed`)
     * @return array{0: array{value: string, expires_at: CarbonImmutable|null}|null, 1: list<array{0: string, 1: string, 2: string|null}>}
     */
    private function finish(ComposeProject $project, InstallRequest $request, FsaContext $context, ?array $link, bool $applied): array
    {
        $warnings = [];
        if ($link === null) {
            $fresh = $this->app->loginLink($project, $request->ownerEmail(), $context->facts->callerUid());
            $link = $fresh->ok ? $fresh->secret(SignInDelivery::SECRET) : null;
            if ($link === null) {
                $warnings[] = ['sign_in_link_unavailable', 'No sign-in link could be issued: '.$fresh->errorMessage(), "Run `fsa login-link {$request->ownerEmail()}` once the cause is fixed."];
            } else {
                $context->redactor->register($link['value']);
            }
        }
        $state = $this->directory->state();
        if (! InstallRecord::isInstalled($state)) {
            $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));
            $this->directory->saveState($state->withHistoryEntry(InstallRecord::EVENT, InstallRecord::entry($request, $state, $digest, $applied)));
        }

        return [$link, $warnings];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  list<array{0: string, 1: string, 2: string|null}>  $warnings
     * @param  array{value: string, expires_at: CarbonImmutable|null}|null  $link
     */
    private function result(InstanceState $state, InstallRequest $request, array $preflight, array $warnings, ?array $link, FsaContext $context, bool $secretsToFile): CommandResult
    {
        $result = CommandResult::ok(self::OPERATION, $this->summary($state, $request->ownerEmail()), changed: true);
        foreach (is_array($preflight['warnings'] ?? null) ? $preflight['warnings'] : [] as $warning) {
            $result = $result->withWarning((string) $warning['code'], (string) $warning['message'], is_string($warning['hint'] ?? null) ? $warning['hint'] : null);
        }
        foreach (is_array($preflight['orphans'] ?? null) ? $preflight['orphans'] : [] as $uuid) {
            $result = $result->withWarning('orphans_found', "Containers of another installation ({$uuid}) ran from this directory.", "Check first that it does not run elsewhere (`docker ps -a --filter label=app.form-schema.instance={$uuid}` shows its containers and their directories). If it was deleted by hand, remove its leftovers with `fsa uninstall --orphans {$uuid} --yes`; fsa refuses while any of its containers runs.");
        }
        $localCa = InstanceSettings::of($state)->localCa() ? LocalCaInfo::warnings((string) InstanceSettings::of($state)->lanAddress()) : [];
        foreach ([...$warnings, ...$localCa, ...array_filter([AcmeStaging::warning($state, $this->directory->root.'/fsa')])] as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return $link === null ? $result : SignInDelivery::deliver($result, $context, $link['value'], $link['expires_at'], $secretsToFile, $request->ownerEmail());
    }

    /**
     * A finished instance: same flags change nothing (R13, AE3), different flags exit 2. No sign-in link is issued
     * on this path (`data.sign_in_link: null`); the warning names `fsa login-link` for one.
     */
    private function unchanged(InstallRequest $request): CommandResult
    {
        $state = $this->directory->state();
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));
        $trusted = fn (string $given): bool => $this->proxy->isTrusted($state, $given);
        $equals = InstallMail::comparator($request->smtpCredentials(), InstallMail::stored($this->directory), InstallRecord::comparator($digest, $trusted));
        $changed = $request->changedFlags(InstallRecord::applied($state), $equals);
        if ($changed !== []) {
            throw new InvalidInput(
                'This instance is installed; '.implode(', ', $changed).' differ from its installation. fsa install changes nothing on an installed instance.',
                'use_config_set',
                InstallFlags::hint($changed, $state),
                ['flags' => InstallFlags::keys($changed, $state)],
            );
        }
        $email = $request->ownerEmailIfKnown();
        $data = [...$this->summary($state, $email), 'installed_at' => InstallRecord::recorded($state)['at'] ?? null, SignInDelivery::SECRET => null];
        $result = CommandResult::ok(self::OPERATION, $data, changed: false)
            ->withWarning('no_sign_in_link', 'The instance is already installed, so no sign-in link was issued.', 'Run '.SignInDelivery::loginLinkCommand($email).' for a one-time sign-in link.');

        // The quick tunnel is transient (ADR 0082): an ended one is no different installation, and install never starts one.
        return $request->phoneHttps() === 'tunnel' && ! InstanceSettings::of($state)->tunnel()
            ? $result->withWarning('tunnel_not_running', 'No quick tunnel runs on this instance; fsa install changes nothing on an installed instance.', 'Start one with `fsa tunnel start`.')
            : $result;
    }

    /** On resume: the earlier inputs still apply; a flag whose phase already ran must not change (exit 2). */
    private function resumable(InstallRequest $request, JournalRecord $record): InstallRequest
    {
        $recorded = [...$record->context(), ...$this->lastFlags($record)];
        $applied = [];
        if ($record->hasCompleted('doctor')) {
            $doctor = $record->phaseData('doctor');
            $applied = ['version' => $doctor['version'] ?? null, 'port' => $doctor['port'] ?? null, ...InstallMode::applied($doctor), ...InstallMail::applied($doctor)];
            foreach (InstallRequest::PROXY_FLAGS as $key) {
                $applied[$key] = is_string($recorded[$key] ?? null) ? $recorded[$key] : null;
            }
        }
        if ($record->hasCompleted('app_install')) {
            foreach (InstallRequest::APP_FLAGS as $key) {
                $applied[$key] = is_string($recorded[$key] ?? null) ? $recorded[$key] : null;
            }
        }
        $stateWritten = $record->hasCompleted('state'); // the SMTP account is in the env files from here on
        $changed = $request->changedFlags($applied + ($stateWritten ? ['smtp_credentials' => null] : []), InstallMail::comparator($request->smtpCredentials(), InstallMail::stored($this->directory)));
        if ($changed !== []) {
            throw new InvalidInput(
                'The interrupted install already applied '.implode(', ', $changed).' with other values.',
                'flag_already_applied',
                'Run `fsa install` again with the values of the first run (or without these flags) to resume, or `fsa uninstall --yes` to start over.',
            );
        }
        $request = $request->over($recorded);
        InstallMail::assertAccountKnown($request, $stateWritten);

        return $request;
    }

    /** Domain-mode flags need --domain; the loopback port belongs to local mode (exit 2). */
    private function assertModeFlags(InstallRequest $request): void
    {
        if ($request->domain() === null && $request->acme() !== []) {
            $flags = array_map(static fn (string $key): string => InstallRequest::FLAGS[$key], array_keys($request->acme()));
            throw new InvalidInput(implode(', ', $flags).' belong(s) to domain mode.', 'domain_required', 'Add --domain <name>, or leave the ACME flags out.');
        }
        if ($request->domain() !== null && $request->port() !== null) {
            throw new InvalidInput('--port belongs to local mode; domain mode publishes 80 and 443 through the edge.', 'invalid_flag', 'Leave --port out with --domain.');
        }
    }

    /** The custom ACME CA root next to the Caddyfile; a resume without the flag needs the file already written. */
    private function writeAcmeCaRoot(InstallRequest $request): void
    {
        $digest = $request->acme()['acme_ca_root'] ?? null;
        if ($digest === null) {
            return;
        }
        $pem = $request->caRoot();
        if ($pem !== null) {
            AcmeCaRoot::write($this->directory, $pem);
        } elseif (! AcmeCaRoot::present($this->directory, $digest)) {
            throw new InvalidInput('The interrupted install needs the ACME CA root again.', 'acme_ca_root_missing', 'Run `fsa install` again with the same --acme-ca-root-file.');
        }
    }

    /** @return array<string, mixed> the inputs recorded by the last resumed run (corrections included) */
    private function lastFlags(JournalRecord $record): array
    {
        $flags = array_filter($record->events(), static fn (array $event): bool => $event['type'] === 'flags');

        return $flags === [] ? [] : $flags[array_key_last($flags)]['data'];
    }

    private function oneOffFailure(string $command, OneOffResult $result, bool $userInput): InstanceException
    {
        return new InstanceException(
            "`{$command}` failed: ".$result->errorMessage(),
            $userInput ? ExitCode::InvalidInput : ExitCode::HealthCheckFailed,
            $userInput ? 'invalid_input' : 'app_install_failed',
            $userInput ? 'Correct the flag and run `fsa install` again: it resumes here.' : 'Read the output, fix the cause, then run `fsa install` again with the same flags: it resumes here.',
            ! $userInput,
            ['code' => $result->errorCode(), 'output' => $result->output],
        );
    }

    /** @return array<string, mixed> */
    private function summary(InstanceState $state, ?string $ownerEmail): array
    {
        $settings = InstanceSettings::of($state);
        $fsa = $this->directory->root.'/fsa';
        $linkFor = $ownerEmail ?? '<owner-email>';

        return [
            'url' => $settings->instanceUrl(),
            'version' => $state->version(),
            'mode' => $state->mode(),
            'dir' => $this->directory->root,
            'project' => $state->project(),
            'port' => $settings->publishesAppPort() ? $settings->port() : null,
            ...($settings->proxy() === null ? [] : ['proxy' => [
                'setup' => $settings->proxy(), 'network' => $settings->proxyNetwork(), 'container' => $settings->proxyContainer(), 'trusted' => $settings->trustedProxies(),
            ]]),
            'mail' => MailSummary::of($state),
            // Local-CA mode (ADR 0082): the trust page for the phone and the root's fingerprint to compare there.
            ...($settings->localCa() ? ['phone_https' => LocalCaInfo::data($state, $this->directory)] : []),
            'owner_email' => $ownerEmail,
            'next_commands' => [...(AcmeStaging::active($state) ? [AcmeStaging::command($fsa)] : []), "{$fsa} status", "{$fsa} login-link {$linkFor}", "{$fsa} doctor --post"],
        ];
    }
}
