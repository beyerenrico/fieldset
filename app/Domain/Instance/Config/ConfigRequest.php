<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\State\InstanceSettings;

/**
 * One `fsa config set <key> <value>` (§7, R32, ADR 0077): the key, the raw value and the ACME options that only
 * `domain` and `acme-ca` take. `--acme-staging` arrives here as `acmeCa` (Let's Encrypt staging); the CA root is the
 * validated PEM of `--acme-ca-root-file`. The journal records the request without the PEM (its SHA-256 instead), so a
 * resumed run can tell "the same change" from another one.
 */
final readonly class ConfigRequest
{
    /**
     * What `--acme-ca-root-file` shows in a command line: the file is not known, only that the same one is needed. A
     * request rebuilt from a journal (`fromContext()`) carries it in place of the PEM.
     */
    public const string ROOT_PLACEHOLDER = '<the same file>';

    public function __construct(
        public string $key,
        public string $value,
        public ?string $acmeEmail = null,
        public ?string $acmeCa = null,
        public ?string $acmeCaRoot = null,
    ) {}

    /** Whether any ACME option was given (they belong to `domain` and `acme-ca` only). */
    public function hasAcmeOptions(): bool
    {
        return $this->acmeEmail !== null || $this->acmeCa !== null || $this->acmeCaRoot !== null;
    }

    public function caRootDigest(): ?string
    {
        return $this->acmeCaRoot === null ? null : hash('sha256', $this->acmeCaRoot);
    }

    /** @return array{key: string, value: string, acme_email: string|null, acme_ca: string|null, acme_ca_root: string|null} */
    public function toContext(): array
    {
        return ['key' => $this->key, 'value' => $this->value, 'acme_email' => $this->acmeEmail, 'acme_ca' => $this->acmeCa, 'acme_ca_root' => $this->caRootDigest()];
    }

    /**
     * Whether a journal's recorded request is this one (a resume must repeat the same change).
     *
     * @param  array<string, mixed>  $context
     */
    public function sameAs(array $context): bool
    {
        $mine = $this->toContext();

        return array_all(array_keys($mine), static fn (string $key): bool => ($context[$key] ?? null) === $mine[$key]);
    }

    /**
     * The request a journal recorded (`toContext()`), for naming the exact command that resumes it. The CA root comes
     * back as its digest only (the PEM is never journaled), which `commandLine()` names as a file to pass again.
     *
     * @param  array<string, mixed>  $context
     */
    public static function fromContext(array $context): ?self
    {
        $string = static fn (string $key): ?string => is_string($context[$key] ?? null) && $context[$key] !== '' ? $context[$key] : null;
        $key = $string('key');
        $value = $string('value');
        if ($key === null || $value === null) {
            return null;
        }
        $request = new self($key, $value, $string('acme_email'), $string('acme_ca'));

        return $string('acme_ca_root') === null ? $request : new self($key, $value, $request->acmeEmail, $request->acmeCa, self::ROOT_PLACEHOLDER);
    }

    /**
     * The flags of the command line (`--yes` included: a URL change needs it without a terminal, other keys ignore
     * it). Let's Encrypt staging is named `--acme-staging`, as the user typed it.
     *
     * @return array<string, string|true>
     */
    public function options(): array
    {
        return array_filter([
            '--acme-email' => $this->acmeEmail,
            ...($this->acmeCa === InstanceSettings::ACME_STAGING ? ['--acme-staging' => true] : ['--acme-ca' => $this->acmeCa]),
            '--acme-ca-root-file' => $this->acmeCaRoot === null ? null : self::ROOT_PLACEHOLDER,
            '--yes' => true,
        ], static fn (string|bool|null $value): bool => $value !== null);
    }

    /** `fsa config set <key> <value>` as a command line for hints (the CA root file is named, not inlined). */
    public function commandLine(): string
    {
        $flags = array_map(static fn (string $flag, string|bool $value): string => $value === true ? $flag : "{$flag} {$value}", array_keys($this->options()), $this->options());

        return "fsa config set {$this->key} {$this->value} ".implode(' ', $flags);
    }
}
