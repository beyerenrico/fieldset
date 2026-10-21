<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use App\Domain\Instance\InstanceException;

/**
 * The result envelope of every `fieldset` command (KTD3, plan "Result envelope"): one JSON document on stdout with
 * `schema_version`, `command`, `status`, `exit_code` (the process's exit code as a number, M12 gate O10; additive, the
 * schema version stays 1), `changed`, `data`, `secrets`, `warnings` and `errors`. Secret values appear only under
 * `secrets` (or in the --secrets-file); anything else is passed through the redactor (R50).
 */
final readonly class CommandResult
{
    public const int SCHEMA_VERSION = 1;

    public const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

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

    /** `changed` is true when the caller knows something changed or the exception says so (partial uninstall). */
    public static function fromException(string $command, InstanceException $e, bool $changed = false): self
    {
        return self::failed($command, $e->exitCode, $e->errorCode, $e->getMessage(), $e->hint, $e->retryable, $e->data, $changed || $e->changed);
    }

    /**
     * An envelope another fieldset process rendered (the detached upgrade supervisor's result file, ADR 0086): its exit code
     * follows from `status` and the first error's code, secrets are never carried over. Unknown shapes become exit 1.
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function fromArray(array $envelope, ExitCode $exitCode): self
    {
        $messages = static fn (mixed $list, string $level): array => array_values(array_map(
            static fn (array $m): Message => $level === 'error'
                ? Message::error((string) ($m['code'] ?? 'unknown'), (string) ($m['message'] ?? ''), is_string($m['hint'] ?? null) ? $m['hint'] : null, ($m['retryable'] ?? false) === true)
                : Message::warning((string) ($m['code'] ?? 'unknown'), (string) ($m['message'] ?? ''), is_string($m['hint'] ?? null) ? $m['hint'] : null),
            array_filter(is_array($list) ? $list : [], is_array(...)),
        ));

        return new self(
            is_string($envelope['command'] ?? null) ? $envelope['command'] : 'unknown', $exitCode, ($envelope['changed'] ?? false) === true,
            is_array($envelope['data'] ?? null) ? $envelope['data'] : [], [], $messages($envelope['warnings'] ?? null, 'warning'), $messages($envelope['errors'] ?? null, 'error'),
        );
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
     * @return array{schema_version: int, command: string, status: string, exit_code: int, changed: bool, data: array<string, mixed>, secrets: list<array<string, string|null>>, warnings: list<array<string, mixed>>, errors: list<array<string, mixed>>}
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
            'exit_code' => $this->exitCode->value,
            'changed' => $this->changed,
            'data' => $data,
            'secrets' => array_map(static fn (Secret $s): array => $s->toArray($secretsFile), $this->secrets),
            'warnings' => array_values($messages($this->warnings)),
            'errors' => array_values($messages($this->errors)),
        ];
    }

    /** Invalid UTF-8 (container logs, one-off output) becomes U+FFFD instead of failing the whole document. */
    public function toJson(?SecretRedactor $redactor = null, ?string $secretsFile = null): string
    {
        return json_encode($this->toArray($redactor, $secretsFile), self::JSON_FLAGS);
    }
}
