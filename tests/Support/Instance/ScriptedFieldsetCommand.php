<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Console\FieldsetCommand;
use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Output\CommandResult;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Contracts\Console\Kernel;

/** A `fieldset:scripted` command whose behaviour a test supplies; exercises the FieldsetCommand base. */
#[Description('Test command for the fieldset console base')]
#[Signature('fieldset:scripted {target? : Optional argument} {--smtp-password-file= : A file flag}')]
final class ScriptedFieldsetCommand extends FieldsetCommand
{
    public int $performed = 0;

    /** @param  Closure(FieldsetContext): CommandResult  $script */
    public function __construct(
        private readonly Closure $script,
        private readonly ?string $prompt = null,
        private readonly bool $needsInstance = true,
    ) {
        parent::__construct();
    }

    /** @param  Closure(FieldsetContext): CommandResult  $script */
    public static function register(Closure $script, ?string $prompt = null, bool $needsInstance = true): self
    {
        $command = new self($script, $prompt, $needsInstance);
        app(Kernel::class)->registerCommand($command);

        return $command;
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        $this->performed++;

        return ($this->script)($context);
    }

    protected function requiresInstance(): bool
    {
        return $this->needsInstance;
    }

    protected function confirmationPrompt(FieldsetContext $context): ?string
    {
        return $this->prompt;
    }
}
