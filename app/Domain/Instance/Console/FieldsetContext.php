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

/** What a `fieldset:*` command's `perform()` gets: host facts, progress, the secret registry and the instance. */
final readonly class FieldsetContext
{
    public function __construct(
        public HostFacts $facts,
        public ProgressReporter $progress,
        public SecretRedactor $redactor,
        public bool $json,
        public bool $yes,
        private ?InstanceDirectory $directory = null,
        public bool $openRequested = false,
    ) {}

    public function directory(): InstanceDirectory
    {
        return $this->directory ?? throw new InstanceException('This command runs without an instance directory.');
    }

    public function hasDirectory(): bool
    {
        return $this->directory instanceof InstanceDirectory;
    }

    /**
     * Whether a URL may go to the browser hand-over instead of the envelope: when asked with --open, or for a
     * person at a desktop terminal (no --json, a TTY and a desktop session). An agent driving fieldset with --json
     * gets the link "as JSON" (R12) in `secrets` or its --secrets-file unless it asks for the browser.
     */
    public function handOverAllowed(): bool
    {
        return $this->openRequested || (! $this->json && $this->facts->tty === true && $this->facts->desktop === true);
    }

    /**
     * Hands a URL to the shim, which opens it in the host's browser after the command exits (§6.2, ADR 0056,
     * ADR 0070). It travels in a 0600 file in the instance directory, never on stdout. Whether the browser really
     * opens is known only to the shim (which prints the link to the terminal if the opener fails), so true means
     * "handed over". False when the host has no desktop session or the launcher cannot open URLs: the command
     * then reports the URL another way (`secrets`, --secrets-file).
     */
    public function openInBrowser(#[SensitiveParameter] string $url): bool
    {
        $file = $this->facts->openUrlFile;
        if ($file === null || $this->facts->desktop !== true || ! $this->hasDirectory() || preg_match('#^https?://[A-Za-z0-9._~:/?\#\[\]@!$&()*+,;=%-]+$#D', $url) !== 1) {
            return false;
        }
        $this->directory()->write($file, $url."\n", 0600);

        return true;
    }

    /** A one-time output: registered for redaction everywhere else, to be added with CommandResult::withSecret. */
    public function secret(string $name, #[SensitiveParameter] string $value, ?CarbonImmutable $expiresAt = null): Secret
    {
        $this->redactor->register($value);

        return new Secret($name, $value, $expiresAt);
    }
}
