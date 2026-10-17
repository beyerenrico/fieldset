<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use App\Domain\Instance\InstanceException;

/**
 * The result envelope of every `fsa` command (KTD3, plan "Result envelope"): one JSON document on stdout with
 * `schema_version`, `command`, `status`, `changed`, `data`, `secrets`, `warnings` and `errors`. Secret values
 * appear only under `secrets` (or in the --secrets-file); anything else is passed through the redactor (R50).
 */
final readonly class CommandResult
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<Secret>  $secrets
     * @param  list<Message>  $warnings
     * @param  list<Message>  $errors
     */
    private function __construct(
        public string $command,
        public ExitCode $exitCode,
        public bool $changed,
        public array $data = [],
        public array $secrets = [],
        public array $warnings = [],
        public array $errors = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function ok(string $command, array $data = [], bool $changed = false): self
    {
        return new self($command, ExitCode::Success, $changed, $data);
    }

    /** @param  array<string, mixed>  $data */
    public static function failed(string $command, ExitCode $exitCode, string $code, string $message, ?string $hint = null, bool $retryable = false, array $data = [], bool $changed = false): self
    {
        return new self($command, $exitCode, $changed, $data, [], [], [Message::error($code, $message, $hint, $retryable)]);
    }

    public static function fromException(string $command, InstanceException $e, bool $changed = false): self
    {
        return self::failed($command, $e->exitCode, $e->errorCode, $e->getMessage(), $e->hint, $e->retryable, $e->data, $changed);
    }

    public function status(): ResultStatus
    {
        return $this->exitCode->status();
    }

    /** @param  array<string, mixed>  $data  merged over the existing data */
    public function withData(array $data): self
    {
        return new self($this->command, $this->exitCode, $this->changed, [...$this->data, ...$data], $this->secrets, $this->warnings, $this->errors);
    }

    public function withChanged(bool $changed): self
    {
        return new self($this->command, $this->exitCode, $changed, $this->data, $this->secrets, $this->warnings, $this->errors);
    }

    public function withExitCode(ExitCode $exitCode): self
    {
        return new self($this->command, $exitCode, $this->changed, $this->data, $this->secrets, $this->warnings, $this->errors);
    }

    public function withSecret(Secret $secret): self
    {
        return new self($this->command, $this->exitCode, $this->changed, $this->data, [...$this->secrets, $secret], $this->warnings, $this->errors);
    }

    public function withWarning(string $code, string $message, ?string $hint = null): self
    {
        return new self($this->command, $this->exitCode, $this->changed, $this->data, $this->secrets, [...$this->warnings, Message::warning($code, $message, $hint)], $this->errors);
    }

    public function withError(string $code, string $message, ?string $hint = null, bool $retryable = false): self
    {
        return new self($this->command, $this->exitCode, $this->changed, $this->data, $this->secrets, $this->warnings, [...$this->errors, Message::error($code, $message, $hint, $retryable)]);
    }

    /**
     * The envelope. Secret values are scrubbed from everything outside `secrets`; with a secrets file they are
     * left out of `secrets` too, which then names the file.
     *
     * @return array{schema_version: int, command: string, status: string, changed: bool, data: array<string, mixed>, secrets: list<array<string, string|null>>, warnings: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public function toArray(?SecretRedactor $redactor = null, ?string $secretsFile = null): array
    {
        $redactor ??= new SecretRedactor;
        foreach ($this->secrets as $secret) {
            $redactor->register($secret->value);
        }
        /** @var array<string, mixed> $data */
        $data = $redactor->redact($this->data);
        $messages = static fn (array $list): array => array_map(static fn (Message $m): array => (array) $redactor->redact($m->toArray()), $list);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'command' => $this->command,
            'status' => $this->status()->value,
            'changed' => $this->changed,
            'data' => $data,
            'secrets' => array_map(static fn (Secret $s): array => $s->toArray($secretsFile), $this->secrets),
            'warnings' => array_values($messages($this->warnings)),
            'errors' => array_values($messages($this->errors)),
        ];
    }

    public function toJson(?SecretRedactor $redactor = null, ?string $secretsFile = null): string
    {
        return json_encode($this->toArray($redactor, $secretsFile), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
