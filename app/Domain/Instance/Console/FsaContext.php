<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\Secret;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/** What an `fsa:*` command's `perform()` gets: host facts, progress, the secret registry and the instance. */
final readonly class FsaContext
{
    public function __construct(
        public HostFacts $facts,
        public ProgressReporter $progress,
        public SecretRedactor $redactor,
        public bool $json,
        public bool $yes,
        private ?InstanceDirectory $directory = null,
    ) {}

    public function directory(): InstanceDirectory
    {
        return $this->directory ?? throw new InstanceException('This command runs without an instance directory.');
    }

    public function hasDirectory(): bool
    {
        return $this->directory instanceof InstanceDirectory;
    }

    /** A one-time output: registered for redaction everywhere else, to be added with CommandResult::withSecret. */
    public function secret(string $name, #[SensitiveParameter] string $value, ?CarbonImmutable $expiresAt = null): Secret
    {
        $this->redactor->register($value);

        return new Secret($name, $value, $expiresAt);
    }
}
