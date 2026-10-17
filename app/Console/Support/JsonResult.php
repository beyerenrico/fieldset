<?php

declare(strict_types=1);

namespace App\Console\Support;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use SensitiveParameter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The JSON document of the app-side one-off commands that `fsa` runs without the Docker socket (KTD25):
 * `app:install`, `identity:login-link`, `identity:login-links`. Same shape as the `fsa` result envelope (KTD3:
 * schema_version, command, status, changed, data, secrets, warnings, errors), so `fsa` can pass it through. A
 * secret (a one-time sign-in link) appears only under `secrets`; passwords never appear (R50).
 */
final readonly class JsonResult
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{name: string, value: string, expires_at: string|null}>  $secrets
     * @param  list<array{code: string, message: string, hint: string|null}>  $warnings
     * @param  list<array{code: string, message: string, hint: string|null, retryable: bool}>  $errors
     */
    private function __construct(
        private string $command,
        private bool $changed = false,
        private array $data = [],
        private array $secrets = [],
        private array $warnings = [],
        private array $errors = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function ok(string $command, array $data = [], bool $changed = false): self
    {
        return new self($command, $changed, $data);
    }

    public static function failed(string $command, string $code, string $message, ?string $hint = null, bool $retryable = false): self
    {
        return new self($command, errors: [['code' => $code, 'message' => $message, 'hint' => $hint, 'retryable' => $retryable]]);
    }

    public function withSecret(string $name, #[SensitiveParameter] string $value, ?CarbonInterface $expiresAt): self
    {
        $secret = ['name' => $name, 'value' => $value, 'expires_at' => $expiresAt?->toImmutable()->utc()->toIso8601ZuluString()];

        return new self($this->command, $this->changed, $this->data, [...$this->secrets, $secret], $this->warnings, $this->errors);
    }

    public function withWarning(string $code, string $message, ?string $hint = null): self
    {
        $warning = ['code' => $code, 'message' => $message, 'hint' => $hint];

        return new self($this->command, $this->changed, $this->data, $this->secrets, [...$this->warnings, $warning], $this->errors);
    }

    /** @return array{schema_version: int, command: string, status: string, changed: bool, data: array<string, mixed>, secrets: list<array{name: string, value: string, expires_at: string|null}>, warnings: list<array{code: string, message: string, hint: string|null}>, errors: list<array{code: string, message: string, hint: string|null, retryable: bool}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'command' => $this->command,
            'status' => $this->errors === [] ? 'ok' : 'failed',
            'changed' => $this->changed,
            'data' => $this->data,
            'secrets' => $this->secrets,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }

    /** Writes the document as the command's only stdout line and returns the exit code. */
    public function emit(Command $command): int
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $command->getOutput()->writeln($json, OutputInterface::OUTPUT_RAW);

        return $this->errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
