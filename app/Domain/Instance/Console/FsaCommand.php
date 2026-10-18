<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ConfirmationRequired;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\ResultRenderer;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
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
 * Base of every `fsa:*` command (ADR 0066, R49, R50). Subclasses implement `perform()` and return a
 * CommandResult; the base:
 *
 * - adds `--json`, `--yes`, `--dir` and `--secrets-file`;
 * - rejects a secret passed as a flag value, and any usage error, with exit 2 in the envelope;
 * - when started by the shim or install.sh, requires their nonce file (exit 3: path not shared with Docker) and
 *   file-path flags inside mounted directories (exit 2), see LaunchGuard;
 * - requires the instance-directory marker (unless `requiresInstance()` is false, i.e. `fsa version`) and binds
 *   the InstanceDirectory, which makes the DockerGateway resolvable (InstanceServiceProvider);
 * - registers the secret values of the instance's env files with the redactor, so they never reach any output;
 * - asks for confirmation of destructive commands, or exits 6 without a TTY (or with --json) and without --yes;
 * - maps exceptions to exit codes and renders the envelope, with progress on stderr; if rendering itself fails,
 *   a minimal envelope still goes out (exit 1, `render_failed`), never a stack trace.
 */
abstract class FsaCommand extends Command
{
    /** Container key of an output that receives progress when there is no stderr (tests). */
    public const string PROGRESS_OUTPUT = 'fsa.progress-output';

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
            return new ResultRenderer($redactor)->render($result, $this->output, $json, $secretsFile);
        } catch (Throwable $e) {
            return $this->renderFallback($result, $e, $json);
        }
    }

    abstract protected function perform(FsaContext $context): CommandResult;

    /** Only `fsa version` runs without an instance directory (KTD1). */
    protected function requiresInstance(): bool
    {
        return true;
    }

    /** The question for a destructive command, or null when nothing needs confirming. */
    protected function confirmationPrompt(FsaContext $context): ?string
    {
        return null;
    }

    /**
     * Whether the command may ask a question: never with --json (stdout carries one document), never without a
     * terminal, and not under --yes (which promises a run without questions) unless the caller ignores it.
     */
    protected function canPrompt(FsaContext $context, bool $ignoreYes = false): bool
    {
        return ! $context->json && ($ignoreYes || ! $context->yes) && $context->facts->hasTerminal($this->input->isInteractive());
    }

    /** Whether the caller asked for the browser hand-over of a sign-in link (`--open` of install and login-link). */
    protected function opensBrowser(): bool
    {
        return false;
    }

    /** Envelope `command`: the name without the `fsa:` prefix. */
    protected function resultName(): string
    {
        return (string) preg_replace('/^fsa:/', '', (string) $this->getName());
    }

    private function buildContext(HostFacts $facts, ProgressReporter $progress, SecretRedactor $redactor, bool $json): FsaContext
    {
        $directory = null;
        if ($this->requiresInstance()) {
            $directory = InstanceDirectory::open($this->instancePath($facts));
            $this->laravel->instance(InstanceDirectory::class, $directory);
            foreach (new EnvFiles($directory)->secretValues() as $secret) {
                $redactor->register($secret);
            }
        }

        return new FsaContext($facts, $progress, $redactor, $json, (bool) $this->option('yes'), $directory, $this->opensBrowser());
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

    private function confirmIfNeeded(FsaContext $context): void
    {
        $question = $this->confirmationPrompt($context);
        if ($question === null || $context->yes) {
            return;
        }
        // With --json stdout carries exactly one document, so a prompt would corrupt it: treat it like no TTY.
        if (! $this->canPrompt($context, ignoreYes: true)) {
            throw new ConfirmationRequired($question);
        }
        if (! $this->confirm($question)) {
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
        $failed = CommandResult::failed($result->command, ExitCode::InternalError, 'render_failed', 'The result could not be printed: '.$e::class.'.', 'Run the command again; `fsa status` shows what it changed.', true, ['exit_code' => $result->exitCode->value], $result->changed);
        $document = json_encode($failed->toArray(), CommandResult::JSON_FLAGS);
        $this->output->writeln($json ? $document : 'fsa '.$result->command.': failed (the result could not be printed)', OutputInterface::OUTPUT_RAW);

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
