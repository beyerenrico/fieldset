<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\ScriptRefresh;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\Confirmation;
use App\Domain\Instance\Output\ConfirmationRequired;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\ResultRenderer;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\Ownership\FirstUseRecording;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use Closure;
use Illuminate\Console\Command;
use Override;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Base of every `fieldset:*` command (ADR 0066, R49, R50). Subclasses implement `perform()` and return a
 * CommandResult; the base:
 *
 * - adds `--json`, `--yes`, `--dir` and `--secrets-file`;
 * - rejects a secret passed as a flag value, and any usage error, with exit 2 in the envelope;
 * - when started by the shim or install.sh, requires their nonce file (exit 3: path not shared with Docker) and
 *   file-path flags inside mounted directories (exit 2), see LaunchGuard;
 * - requires the instance-directory marker (unless `requiresInstance()` is false, i.e. `fieldset version`) and binds
 *   the InstanceDirectory, which makes the DockerGateway resolvable (InstanceServiceProvider);
 * - registers the secret values of the instance's env files with the redactor, so they never reach any output;
 * - asks for confirmation of destructive commands, or exits 6 without a TTY (or with --json) and without --yes, with the
 *   facts the question names under `data` (Confirmation, M12 gate O6);
 * - maps exceptions to exit codes and renders the envelope, with progress on stderr; if rendering itself fails,
 *   a minimal envelope still goes out (exit 1, `render_failed`), never a stack trace;
 * - afterwards, when `recordsOwner()` is true and the command started its work, saves the owner the shim measured in
 *   this run with the instance if state.json lacks it (FirstUseRecording, 2.1.1 U4, KTD6); read-only commands never do;
 * - afterwards, success or failure, gives the instance this image's `fieldset` script when the shim that started the
 *   command is older (ScriptRefresh, 2.1.1 U15), unless `refreshesLauncher()` is false.
 */
abstract class FieldsetCommand extends Command
{
    /** Container key of an output that receives progress when there is no stderr (tests). */
    public const string PROGRESS_OUTPUT = 'fieldset.progress-output';

    /** Exit codes of a command that did not start its work (usage, a missing confirmation, aborted): nothing is recorded. */
    private const array NOT_STARTED = [ExitCode::InvalidInput, ExitCode::ConfirmationRequired, ExitCode::AbortedUnchanged];

    public function __construct()
    {
        parent::__construct();
        $options = [
            ['json', InputOption::VALUE_NONE, 'Print one JSON document on stdout and NDJSON progress on stderr'],
            ['yes', InputOption::VALUE_NONE, 'Confirm destructive steps (required without a terminal or with --json)'],
            ['dir', InputOption::VALUE_REQUIRED, 'Instance directory (default: the current directory)'],
            ['secrets-file', InputOption::VALUE_REQUIRED, 'Write one-time secrets to this file (mode 0600) instead of stdout'],
        ];
        foreach ($options as [$name, $mode, $description]) {
            if (! $this->getDefinition()->hasOption($name)) {
                $this->addOption($name, null, $mode, $description);
            }
        }
    }

    #[Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $flag = SecretFlagGuard::find($input);
        if ($flag !== null) {
            return $this->renderEarly($input, $output, InvalidInput::secretFlag($flag));
        }

        try {
            return parent::run($input, $output);
        } catch (ConsoleException $e) {
            return $this->renderEarly($input, $output, InvalidInput::usage($e->getMessage()));
        }
    }

    final public function handle(HostFacts $facts): int
    {
        $json = (bool) $this->option('json');
        $redactor = new SecretRedactor;
        $secretsFile = null;
        $context = null;

        try {
            $this->checkLaunch($facts);
            $secretsFile = $this->secretsFile();
            $context = $this->buildContext($facts, new ProgressReporter($this->progressOutput(), $json, $redactor), $redactor, $json);
            $this->confirmIfNeeded($context);
            $result = $this->perform($context);
        } catch (InstanceException $e) {
            $result = CommandResult::fromException($this->resultName(), $e);
        } catch (Throwable $e) {
            $result = CommandResult::failed($this->resultName(), ExitCode::InternalError, 'internal_error', $e->getMessage(), null, false, ['exception' => $e::class]);
        }

        try {
            $exitCode = new ResultRenderer($redactor)->render($result, $this->output, $json, $secretsFile);
        } catch (Throwable $e) {
            $exitCode = $this->renderFallback($result, $e, $json);
        }
        $this->recordOwner($context, $result);
        $this->refreshLauncher($context);

        return $exitCode;
    }

    abstract protected function perform(FieldsetContext $context): CommandResult;

    /** Only `fieldset version` runs without an instance directory (KTD1). */
    protected function requiresInstance(): bool
    {
        return true;
    }

    /** The question for a destructive command (with the facts it names), or null when nothing needs confirming. */
    protected function confirmationPrompt(FieldsetContext $context): Confirmation|string|null
    {
        return null;
    }

    /**
     * Before any question or plan (M12 gate C4): an unfinished operation this command would not resume is reported at once
     * (exit 9 `operation_unfinished`), not after a prompt, a refusal of its own or a one-off that counts what is lost.
     *
     * @param  Closure(JournalRecord): bool  $resumes  whether this command continues the open journal
     *
     * @throws OperationUnfinished
     */
    protected function refuseUnfinished(Closure $resumes): ?JournalRecord
    {
        $open = $this->laravel->make(OperationJournal::class)->current();
        if ($open instanceof JournalRecord && ! $resumes($open)) {
            throw new OperationUnfinished($open);
        }

        return $open;
    }

    /**
     * Whether the command may ask a question: never with --json (stdout carries one document), never without a
     * terminal, and not under --yes (which promises a run without questions) unless the caller ignores it.
     */
    protected function canPrompt(FieldsetContext $context, bool $ignoreYes = false): bool
    {
        return ! $context->json && ($ignoreYes || ! $context->yes) && $context->facts->hasTerminal($this->input->isInteractive());
    }

    /**
     * Whether this command saves the owner the shim measured in this run with the instance afterwards (FirstUseRecording,
     * 2.1.1 U4, KTD6): true for the state-changing commands and `doctor`; read-only commands never write state.
     */
    protected function recordsOwner(): bool
    {
        return false;
    }

    /** Whether the instance's `fieldset` script may be refreshed after this command (ScriptRefresh); not by `uninstall`. */
    protected function refreshesLauncher(): bool
    {
        return true;
    }

    /** Whether the caller asked for the browser hand-over of a sign-in link (`--open` of install and login-link). */
    protected function opensBrowser(): bool
    {
        return false;
    }

    /** Envelope `command`: the name without the `fieldset:` prefix. */
    protected function resultName(): string
    {
        return (string) preg_replace('/^fieldset:/', '', (string) $this->getName());
    }

    private function buildContext(HostFacts $facts, ProgressReporter $progress, SecretRedactor $redactor, bool $json): FieldsetContext
    {
        $directory = null;
        if ($this->requiresInstance()) {
            $directory = InstanceDirectory::open($this->instancePath($facts));
            $this->laravel->instance(InstanceDirectory::class, $directory);
            foreach (new EnvFiles($directory)->secretValues() as $secret) {
                $redactor->register($secret);
            }
        }

        return new FieldsetContext($facts, $progress, $redactor, $json, (bool) $this->option('yes'), $directory, $this->opensBrowser());
    }

    /** The first state-changing command or doctor records the measured owner (KTD6); this never changes the command's result. */
    private function recordOwner(?FieldsetContext $context, CommandResult $result): void
    {
        if (! $context instanceof FieldsetContext || ! $context->hasDirectory() || ! $this->recordsOwner() || in_array($result->exitCode, self::NOT_STARTED, true)) {
            return;
        }

        try {
            $this->laravel->make(FirstUseRecording::class)->afterCommand($context->facts, $context->progress);
        } catch (Throwable) {
            // FirstUseRecording reports its own failures; only resolving it can throw here, and a later command records then
        }
    }

    /** An older shim gets this image's script once the command is done (KTD7); this never changes the command's result. */
    private function refreshLauncher(?FieldsetContext $context): void
    {
        if (! $context instanceof FieldsetContext || ! $context->hasDirectory() || ! $this->refreshesLauncher()) {
            return;
        }

        try {
            $this->laravel->make(ScriptRefresh::class)->afterCommand($context->facts, $context->progress);
        } catch (Throwable) {
            // ScriptRefresh reports its own failures; only resolving it can throw here, and the script then stays as it is
        }
    }

    /** Nonce and file-path checks for a command started by the shim or install.sh (LaunchGuard, ADR 0070). */
    private function checkLaunch(HostFacts $facts): void
    {
        $instancePath = $this->instancePath($facts);
        LaunchGuard::assertVisible($facts, $instancePath);
        LaunchGuard::assertFilePaths($facts, $instancePath, $this->options());
    }

    private function instancePath(HostFacts $facts): string
    {
        $option = $this->option('dir');
        $path = is_string($option) && $option !== '' ? $option : ($facts->instanceDir ?? (string) getcwd());

        return rtrim(str_starts_with($path, '/') ? $path : getcwd().'/'.$path, '/');
    }

    private function confirmIfNeeded(FieldsetContext $context): void
    {
        $question = $this->confirmationPrompt($context);
        if ($question === null || $context->yes) {
            return;
        }
        $confirmation = is_string($question) ? new Confirmation($question) : $question;
        // With --json stdout carries exactly one document, so a prompt would corrupt it: treat it like no TTY.
        if (! $this->canPrompt($context, ignoreYes: true)) {
            throw ConfirmationRequired::of($confirmation);
        }
        if (! $this->confirm($confirmation->question)) {
            throw new InstanceException('Cancelled; nothing was changed.', ExitCode::AbortedUnchanged, 'cancelled');
        }
    }

    /** The validated --secrets-file path (KTD3): its directory must exist; the shim mounts it at the same path. */
    private function secretsFile(): ?string
    {
        $option = $this->option('secrets-file');
        if (! is_string($option) || $option === '') {
            return null;
        }
        $path = str_starts_with($option, '/') ? $option : getcwd().'/'.$option;
        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            throw new InvalidInput('The directory of --secrets-file does not exist or is not writable.', 'secrets_file_unwritable', 'Pass a path in an existing directory you can write to.');
        }
        if (is_link($path) || is_dir($path)) {
            throw new InvalidInput('--secrets-file must name a regular file, not a symlink or directory.', 'secrets_file_unwritable');
        }

        return $path;
    }

    /**
     * The envelope could not be rendered (e.g. an output stream failed): a minimal document built without the
     * result's data, secrets or messages, so nothing unredacted can leak through it.
     */
    private function renderFallback(CommandResult $result, Throwable $e, bool $json): int
    {
        $failed = CommandResult::failed($result->command, ExitCode::InternalError, 'render_failed', 'The result could not be printed: '.$e::class.'.', 'Run the command again; `fieldset status` shows what it changed.', true, ['exit_code' => $result->exitCode->value], $result->changed);
        $document = json_encode($failed->toArray(), CommandResult::JSON_FLAGS);
        $this->output->writeln($json ? $document : 'fieldset '.$result->command.': failed (the result could not be printed)', OutputInterface::OUTPUT_RAW);

        return ExitCode::InternalError->value;
    }

    private function renderEarly(InputInterface $input, OutputInterface $output, InstanceException $e): int
    {
        $result = CommandResult::fromException($this->resultName(), $e);

        return new ResultRenderer(new SecretRedactor)->render($result, $output, $input->hasParameterOption('--json', true));
    }

    private function progressOutput(): OutputInterface
    {
        $output = $this->output->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }
        $bound = $this->laravel->bound(self::PROGRESS_OUTPUT) ? $this->laravel->make(self::PROGRESS_OUTPUT) : null;

        return $bound instanceof OutputInterface ? $bound : new NullOutput;
    }
}
