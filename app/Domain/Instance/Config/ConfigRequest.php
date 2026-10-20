<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\State\InstanceSettings;
use SensitiveParameter;

/**
 * One `fsa config set <key> <value>` (§7, R32, ADR 0077): the key, the raw value and the ACME options that only
 * `domain` and `acme-ca` take. `--acme-staging` arrives here as `acmeCa` (Let's Encrypt staging); the CA root is the
 * validated PEM of `--acme-ca-root-file`. The journal records the request without the PEM (its SHA-256 instead), so a
 * resumed run can tell "the same change" from another one. `mail` brings its SMTP flags (MailOptions, journaled) and
 * credentials (SmtpCredentials, never journaled; ADR 0080); `operator-email` its `--revoke-previous`;
 * `backup-encryption on` the identity path (journaled) and the passphrase (never journaled; ADR 0084).
 */
final readonly class ConfigRequest
{
    /**
     * What `--acme-ca-root-file` shows in a command line: the file is not known, only that the same one is needed. A
     * request rebuilt from a journal (`fromContext()`) carries it in place of the PEM.
     */
    public const string ROOT_PLACEHOLDER = '<the same file>';

    /** What a repeated `backup-encryption on` names for its passphrase: never the passphrase, only that it is needed. */
    public const string PASSPHRASE_PLACEHOLDER = '<passphrase file>';

    public function __construct(
        public string $key,
        public string $value,
        public ?string $acmeEmail = null,
        public ?string $acmeCa = null,
        public ?string $acmeCaRoot = null,
        public ?MailOptions $mail = null,
        public ?SmtpCredentials $credentials = null,
        public bool $revokePrevious = false,
        public ?string $identityOut = null,
        #[SensitiveParameter] public ?string $passphrase = null,
    ) {}

    /** Whether `--identity-out` or a passphrase source was given (they belong to `backup-encryption`). */
    public function hasKeyFileOptions(): bool
    {
        return $this->identityOut !== null || $this->passphrase !== null;
    }

    /** Whether any option of `config set mail` or `config set operator-email` was given. */
    public function hasKeyOptions(): bool
    {
        return ($this->mail instanceof MailOptions && (! $this->mail->isEmpty() || $this->mail->testTo !== null)) || $this->credentials instanceof SmtpCredentials || $this->revokePrevious;
    }

    /** Whether any ACME option was given (they belong to `domain` and `acme-ca` only). */
    public function hasAcmeOptions(): bool
    {
        return $this->acmeEmail !== null || $this->acmeCa !== null || $this->acmeCaRoot !== null;
    }

    public function caRootDigest(): ?string
    {
        return $this->acmeCaRoot === null ? null : hash('sha256', $this->acmeCaRoot);
    }

    /** @return array<string, mixed> key, value, ACME options and, for `mail`, its SMTP options */
    public function toContext(): array
    {
        return [
            'key' => $this->key, 'value' => $this->value, 'acme_email' => $this->acmeEmail, 'acme_ca' => $this->acmeCa, 'acme_ca_root' => $this->caRootDigest(),
            ...($this->mail instanceof MailOptions ? ['mail' => $this->mail->toContext()] : []),
            ...($this->identityOut !== null ? ['identity_out' => $this->identityOut] : []),
        ];
    }

    /**
     * Whether a journal's recorded request is this one (a resume must repeat the same change).
     *
     * @param  array<string, mixed>  $context
     */
    public function sameAs(array $context): bool
    {
        // Where the password came from (a file or stdin) is no part of the change: a resume may pass it the other way.
        $withoutSource = static fn (array $values): array => is_array($values['mail'] ?? null) ? ['mail' => array_diff_key($values['mail'], ['password_file' => true])] + $values : $values;
        $mine = $withoutSource($this->toContext());
        $context = $withoutSource($context);

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
        $mail = is_array($context['mail'] ?? null) ? MailOptions::fromContext($context['mail']) : null;

        return new self($key, $value, $string('acme_email'), $string('acme_ca'), $string('acme_ca_root') === null ? null : self::ROOT_PLACEHOLDER, $mail, identityOut: $string('identity_out'));
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
            ...($this->mail instanceof MailOptions ? $this->mail->flags() : []),
            '--identity-out' => $this->identityOut,
            ...($this->identityOut !== null ? ['--passphrase-file' => self::PASSPHRASE_PLACEHOLDER] : []),
            '--yes' => true,
        ], static fn (string|bool|null $value): bool => $value !== null);
    }

    /**
     * `fsa config set <key> <value>` as a command line for hints (the CA root file is named, not inlined). Values a shell
     * would split are single-quoted (a sender name); placeholders such as `<the same file>` stay as they are.
     */
    public function commandLine(): string
    {
        $flags = array_map(static fn (string $flag, string|bool $value): string => $value === true ? $flag : $flag.' '.self::shellWord($value), array_keys($this->options()), $this->options());

        return "fsa config set {$this->key} {$this->value} ".implode(' ', $flags);
    }

    /** A value as one shell word: quoted unless it is a placeholder or safe as it stands. */
    public static function shellWord(string $value): string
    {
        return preg_match('/^(?:<[a-z ]+>|[A-Za-z0-9@%+=:,.\/_-]+)$/D', $value) === 1 ? $value : "'".str_replace("'", "'\\''", $value)."'";
    }
}
