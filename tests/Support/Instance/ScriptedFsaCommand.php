<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Console\FsaCommand;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Output\CommandResult;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Contracts\Console\Kernel;

/** An `fsa:scripted` command whose behaviour a test supplies; exercises the FsaCommand base. */
#[Description('Test command for the fsa console base')]
#[Signature('fsa:scripted {target? : Optional argument} {--smtp-password-file= : A file flag}')]
final class ScriptedFsaCommand extends FsaCommand
{
    public int $performed = 0;

    /** @param  Closure(FsaContext): CommandResult  $script */
    public function __construct(
        private readonly Closure $script,
        private readonly ?string $prompt = null,
        private readonly bool $needsInstance = true,
    ) {
        parent::__construct();
    }

    /** @param  Closure(FsaContext): CommandResult  $script */
    public static function register(Closure $script, ?string $prompt = null, bool $needsInstance = true): self
    {
        $command = new self($script, $prompt, $needsInstance);
        app(Kernel::class)->registerCommand($command);

        return $command;
    }

    protected function perform(FsaContext $context): CommandResult
    {
        $this->performed++;

        return ($this->script)($context);
    }

    protected function requiresInstance(): bool
    {
        return $this->needsInstance;
    }

    protected function confirmationPrompt(FsaContext $context): ?string
    {
        return $this->prompt;
    }
}
