<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

/**
 * One doctor check's outcome, in the shape of the platform's HealthResult (key, status, summary, details) plus the
 * one-line fix R10 asks for. Details are plain values for the JSON envelope; never secrets.
 */
final readonly class CheckResult
{
    /**
     * @param  array<string, mixed>  $details
     * @param  string|null  $code  the warning code when it is more specific than `doctor_<key>` (e.g. `proxy_address_changed`)
     */
    public function __construct(
        public string $key,
        public CheckStatus $status,
        public string $summary,
        public ?string $hint = null,
        public array $details = [],
        public ?string $code = null,
    ) {}

    /** @param  array<string, mixed>  $details */
    public static function ok(string $key, string $summary, array $details = []): self
    {
        return new self($key, CheckStatus::Ok, $summary, null, $details);
    }

    /** @param  array<string, mixed>  $details */
    public static function warn(string $key, string $summary, string $hint, array $details = [], ?string $code = null): self
    {
        return new self($key, CheckStatus::Warn, $summary, $hint, $details, $code);
    }

    /** @param  array<string, mixed>  $details */
    public static function fail(string $key, string $summary, string $hint, array $details = []): self
    {
        return new self($key, CheckStatus::Fail, $summary, $hint, $details);
    }

    /**
     * The check could not find out (2.1.1 U4): counts like a warning, never like a failure.
     *
     * @param  array<string, mixed>  $details
     */
    public static function unknown(string $key, string $summary, string $hint, array $details = [], ?string $code = null): self
    {
        return new self($key, CheckStatus::Unknown, $summary, $hint, $details, $code);
    }

    /** @param  array<string, mixed>  $details */
    public static function skipped(string $key, string $summary, array $details = []): self
    {
        return new self($key, CheckStatus::Skipped, $summary, null, $details);
    }

    /** The code of this result as a warning of the command: its own, else `doctor_<key>`. */
    public function warningCode(): string
    {
        return $this->code ?? 'doctor_'.$this->key;
    }

    /** @return array{key: string, status: string, summary: string, hint: string|null, details: array<string, mixed>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'status' => $this->status->value, 'summary' => $this->summary, 'hint' => $this->hint, 'details' => $this->details];
    }
}
