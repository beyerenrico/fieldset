<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

/**
 * One `fsa config set <key> <value>` (§7, R32, ADR 0077): the key, the raw value and the ACME options that only
 * `domain` and `acme-ca` take. `--acme-staging` arrives here as `acmeCa` (Let's Encrypt staging); the CA root is the
 * validated PEM of `--acme-ca-root-file`. The journal records the request without the PEM (its SHA-256 instead), so a
 * resumed run can tell "the same change" from another one.
 */
final readonly class ConfigRequest
{
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

    /** `fsa config set <key> <value>` as a command line for hints (the CA root file is named, not inlined). */
    public function commandLine(): string
    {
        $flags = array_filter([
            $this->acmeEmail === null ? null : '--acme-email '.$this->acmeEmail,
            $this->acmeCa === null ? null : '--acme-ca '.$this->acmeCa,
            $this->acmeCaRoot === null ? null : '--acme-ca-root-file <the same file>',
        ]);

        return trim("fsa config set {$this->key} {$this->value} ".implode(' ', $flags)).' --yes';
    }
}
