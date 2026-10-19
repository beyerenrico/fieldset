<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Docker\ProcessOutcome;
use Carbon\CarbonImmutable;
use SensitiveParameter;
use Throwable;

/**
 * The JSON document an app-side one-off prints (`app:install`, `identity:login-link`, `identity:login-links`;
 * App\Console\Support\JsonResult, the fsa envelope shape). The last stdout line that parses as such a document
 * counts; compose and the entrypoint print nothing else on stdout. Secrets stay in this object: callers hand them
 * to the result's `secrets` and never to data, warnings, errors or the journal (R50).
 */
final readonly class OneOffResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{name: string, value: string, expires_at: string|null}>  $secrets
     * @param  list<array{code: string, message: string, hint: string|null}>  $warnings
     * @param  list<array{code: string, message: string, hint: string|null}>  $errors
     */
    private function __construct(
        public bool $ok,
        public array $data,
        #[SensitiveParameter] private array $secrets,
        public array $warnings,
        public array $errors,
        public string $output,
    ) {}

    public static function fromOutcome(ProcessOutcome $outcome): self
    {
        $document = self::document($outcome->stdout);
        $tail = $outcome->stderrTail(10);
        if ($document === null) {
            $code = $outcome->timedOut ? 'one_off_timeout' : 'one_off_failed';

            return new self(false, [], [], [], [['code' => $code, 'message' => 'The app command printed no result (exit '.$outcome->exitCode.').', 'hint' => null]], $tail);
        }

        return new self(
            $outcome->successful() && ($document['status'] ?? null) === 'ok',
            is_array($document['data'] ?? null) ? $document['data'] : [],
            self::secretsOf($document['secrets'] ?? null),
            self::messages($document['warnings'] ?? null),
            self::messages($document['errors'] ?? null),
            $tail,
        );
    }

    public function errorCode(): string
    {
        return $this->errors[0]['code'] ?? 'one_off_failed';
    }

    public function errorMessage(): string
    {
        return $this->errors[0]['message'] ?? 'The app command failed.';
    }

    public function errorHint(): ?string
    {
        return $this->errors[0]['hint'] ?? null;
    }

    public function hasWarning(string $code): bool
    {
        return array_any($this->warnings, static fn (array $warning): bool => $warning['code'] === $code);
    }

    /** @return array{value: string, expires_at: CarbonImmutable|null}|null */
    public function secret(string $name): ?array
    {
        $secret = array_find($this->secrets, static fn (array $entry): bool => $entry['name'] === $name);
        if ($secret === null) {
            return null;
        }

        try {
            $expires = $secret['expires_at'] === null ? null : CarbonImmutable::parse($secret['expires_at']);
        } catch (Throwable) {
            $expires = null;
        }

        return ['value' => $secret['value'], 'expires_at' => $expires];
    }

    /**
     * Every secret whose name starts with `$prefix` (demo passwords: `demo_password:<email>`), by the rest of its name.
     *
     * @return array<string, string>
     */
    public function secretsWithPrefix(string $prefix): array
    {
        $found = [];
        foreach ($this->secrets as $secret) {
            if (str_starts_with($secret['name'], $prefix) && strlen($secret['name']) > strlen($prefix)) {
                $found[substr($secret['name'], strlen($prefix))] = $secret['value'];
            }
        }

        return $found;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secrets' => '[redacted]'];
    }

    /** @return array<string, mixed>|null */
    private static function document(string $stdout): ?array
    {
        foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
            if (! str_starts_with(ltrim($line), '{')) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['schema_version'], $decoded['status'])) {
                return $decoded;
            }
        }

        return null;
    }

    /** @return list<array{name: string, value: string, expires_at: string|null}> */
    private static function secretsOf(mixed $secrets): array
    {
        $list = [];
        foreach (is_array($secrets) ? $secrets : [] as $secret) {
            if (is_array($secret) && is_string($secret['name'] ?? null) && is_string($secret['value'] ?? null)) {
                $list[] = ['name' => $secret['name'], 'value' => $secret['value'], 'expires_at' => is_string($secret['expires_at'] ?? null) ? $secret['expires_at'] : null];
            }
        }

        return $list;
    }

    /** @return list<array{code: string, message: string, hint: string|null}> */
    private static function messages(mixed $messages): array
    {
        $list = [];
        foreach (is_array($messages) ? $messages : [] as $message) {
            if (is_array($message) && is_string($message['code'] ?? null)) {
                $list[] = ['code' => $message['code'], 'message' => is_string($message['message'] ?? null) ? $message['message'] : '', 'hint' => is_string($message['hint'] ?? null) ? $message['hint'] : null];
            }
        }

        return $list;
    }
}
